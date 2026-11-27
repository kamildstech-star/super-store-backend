<?php

namespace App\Http\Controllers;

use App\Services\PosCashDrawer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PosCashDrawerController extends Controller
{
    private const MONEY = ['required', 'integer', 'between:0,'.PosCashDrawer::MAX_CASH];

    public function status(): JsonResponse
    {
        $status = DB::table('pos_cash_drawers as d')->leftJoin('pos_cash_sessions as s', 's.id', '=', 'd.active_session_id')
            ->where('d.id', 1)->select('s.*', 'd.tracking_enabled')->first();
        $enabled = (bool) $status->tracking_enabled;
        unset($status->tracking_enabled);

        return response()->json(['tracking_enabled' => $enabled, 'active' => $status->id ? PosCashDrawer::summary($status) : null])
            ->header('Cache-Control', 'no-store');
    }

    public function history(): JsonResponse
    {
        $page = DB::table('pos_cash_sessions')->whereNotNull('closed_at')->orderByDesc('id')->paginate(10);

        return response()->json($page->through(PosCashDrawer::summary(...)))->header('Cache-Control', 'no-store');
    }

    public function movements(int $session): JsonResponse
    {
        abort_unless(DB::table('pos_cash_sessions')->where('id', $session)->exists(), 404);
        $page = DB::table('pos_cash_movements')->where('session_id', $session)->orderByDesc('id')->paginate(10);

        return response()->json($page->through($this->movement(...)))->header('Cache-Control', 'no-store');
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'opening_minor' => self::MONEY]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $drawer = PosCashDrawer::lock();
            $actor = $request->user();
            if ($past = DB::table('pos_cash_sessions')->where('open_key', $data['request_id'])->first()) {
                if ((int) $past->opened_by !== $actor->id || (int) $past->opening_minor !== (int) $data['opening_minor']) {
                    throw new ConflictHttpException('This opening reference was used for a different submission.');
                }

                return response()->json(PosCashDrawer::summary($past));
            }
            if ($drawer->active_session_id !== null) {
                throw new ConflictHttpException('A cash shift is already open. Refresh the drawer to see it.');
            }
            $id = DB::table('pos_cash_sessions')->insertGetId([
                'open_key' => $data['request_id'], 'opened_by' => $actor->id, 'opener' => $actor->name,
                'opening_minor' => $data['opening_minor'], 'opened_at' => now(),
            ]);
            DB::table('pos_cash_drawers')->where('id', 1)->update(['active_session_id' => $id, 'tracking_enabled' => true]);

            return response()->json(PosCashDrawer::summary(DB::table('pos_cash_sessions')->find($id)), 201);
        }, 3);
    }

    public function move(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'kind' => ['required', 'in:in,out'],
            'amount_minor' => ['required', 'integer', 'between:1,'.PosCashDrawer::MAX_CASH], 'reason' => ['required', 'string', 'max:160'],
        ]);

        return DB::transaction(function () use ($request, $session, $data): JsonResponse {
            $drawer = PosCashDrawer::lock();
            $actor = $request->user();
            if ($past = DB::table('pos_cash_movements')->where('request_id', $data['request_id'])->first()) {
                if ((int) $past->session_id !== $session || (int) $past->user_id !== $actor->id || $past->kind !== $data['kind']
                    || (int) $past->amount_minor !== (int) $data['amount_minor'] || $past->reason !== $data['reason']) {
                    throw new ConflictHttpException('This cash movement reference was used for a different submission.');
                }

                return response()->json($this->movement($past));
            }
            PosCashDrawer::requireSession($drawer, $session);
            $id = DB::table('pos_cash_movements')->insertGetId($data + [
                'session_id' => $session, 'user_id' => $actor->id, 'actor' => $actor->name, 'posted_at' => now(),
            ]);
            PosCashDrawer::add($session, $data['kind'] === 'in' ? 'cash_in_minor' : 'cash_out_minor', (int) $data['amount_minor']);

            return response()->json($this->movement(DB::table('pos_cash_movements')->find($id)), 201);
        }, 3);
    }

    public function close(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'], 'counted_minor' => self::MONEY, 'revision' => ['required', 'integer', 'min:0'],
        ]);

        return DB::transaction(function () use ($request, $session, $data): JsonResponse {
            $drawer = PosCashDrawer::lock();
            $actor = $request->user();
            $shift = DB::table('pos_cash_sessions')->find($session);
            abort_unless($shift, 404);
            if ($shift->close_key === $data['request_id']) {
                if ((int) $shift->closed_by !== $actor->id || (int) $shift->counted_minor !== (int) $data['counted_minor'] || (int) $shift->revision !== (int) $data['revision']) {
                    throw new ConflictHttpException('This closing reference was used for a different submission.');
                }

                return response()->json(PosCashDrawer::summary($shift));
            }
            PosCashDrawer::requireSession($drawer, $session);
            if ((int) $shift->revision !== (int) $data['revision']) {
                throw new ConflictHttpException('Cash activity changed the expected balance. Review the refreshed drawer and count again.');
            }
            if (DB::table('pos_cash_sessions')->where('close_key', $data['request_id'])->exists()) {
                throw new ConflictHttpException('This closing reference was used for a different shift.');
            }
            $expected = PosCashDrawer::expected($shift);
            DB::table('pos_cash_sessions')->where('id', $session)->update([
                'close_key' => $data['request_id'], 'closed_by' => $actor->id, 'closer' => $actor->name, 'closed_at' => now(),
                'expected_minor' => $expected, 'counted_minor' => $data['counted_minor'], 'variance_minor' => (int) $data['counted_minor'] - $expected,
            ]);
            DB::table('pos_cash_drawers')->where('id', 1)->update(['active_session_id' => null]);

            return response()->json(PosCashDrawer::summary(DB::table('pos_cash_sessions')->find($session)));
        }, 3);
    }

    private function movement(object $row): array
    {
        $data = (array) $row;
        unset($data['request_id']);
        $data['amount_minor'] = (string) $row->amount_minor;
        $data['posted_at'] = \Carbon\CarbonImmutable::parse($row->posted_at, 'UTC')->toAtomString();

        return $data;
    }
}

<?php

namespace App\Http\Controllers\Store;

use App\Actions\StoreOrders;
use App\Http\Controllers\Controller;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Signed events from a store, handled at once: a 200 means the order is safely stored. Runs in VerifyStoreSignature's shop context. */
class StoreWebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        /** @var StoreConnection $connection */
        $connection = $request->attributes->get('storeConnection');
        $body = $request->json()->all();

        $envelope = Validator::make($body + ['event_id' => $request->header('X-HOK-Event-Id')], [
            'event_id' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['order.created', 'order.cancelled'])],
            'data' => ['required', 'array'],
        ]);

        if ($envelope->fails()) {
            return $this->invalid($envelope->errors()->toArray());
        }

        return $body['type'] === 'order.created'
            ? $this->orderCreated($connection, $body['data'], (string) $request->header('X-HOK-Event-Id'))
            : $this->orderCancelled($connection, $body['data']);
    }

    protected function orderCreated(StoreConnection $connection, array $data, string $eventId): JsonResponse
    {
        // A cancellation still gets through a pause; a new sale doesn't.
        if (! $connection->isLive()) {
            return StoreApiController::error('connection_paused', 'This connection is paused. Stop selling until it resumes.', 403);
        }

        $validator = Validator::make($data, [
            'external_order_id' => ['required', 'string', 'max:100'],
            'placed_at' => ['required', 'date'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'string', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:50', Rule::requiredIf(fn () => ($data['payment']['method'] ?? null) === StoreOrder::COD)],
            'customer.address' => ['nullable', 'string', 'max:500'],
            'customer.area' => ['nullable', 'string', 'max:200'],
            'payment' => ['required', 'array'],
            'payment.method' => ['required', Rule::in([StoreOrder::PREPAID, StoreOrder::COD])],
            'payment.amount_paid' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['nullable', 'integer', 'min:1', 'required_without:items.*.external_sku'],
            'items.*.external_sku' => ['nullable', 'string', 'max:100', 'required_without:items.*.product_id'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        return $this->stored(StoreOrders::receive($connection, $validator->validated(), $eventId));
    }

    protected function orderCancelled(StoreConnection $connection, array $data): JsonResponse
    {
        $validator = Validator::make($data, [
            'external_order_id' => ['required', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $order = StoreOrders::find($connection, $data['external_order_id']);

        if ($order === null) {
            return StoreApiController::error('not_found', 'No order with that id on this connection.', 404);
        }

        return $this->stored(StoreOrders::cancelFromStore($order, $data['reason'] ?? null));
    }

    protected function stored(StoreOrder $order): JsonResponse
    {
        return response()->json(['data' => ['external_order_id' => $order->external_order_id, 'status' => $order->status->value]]);
    }

    protected function invalid(array $fields): JsonResponse
    {
        return StoreApiController::error('validation_failed', 'Some of the values aren\'t valid.', 422, $fields);
    }
}

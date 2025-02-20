<?php

namespace App\Http\Controllers;

use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Models\Order;
use App\Models\Item;
use App\Models\OrderDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;


use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Define custom filters
        $customDateFilter = AllowedFilter::callback('date', function ($query, $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            if (is_string($value)) {
                Log::info('Date filter value: ' . $value);
                $dates = explode(',', $value);
                if (count($dates) == 2) {
                    $startDate = Carbon::parse($dates[0])->startOfDay();
                    $endDate = Carbon::parse($dates[1])->endOfDay();
                    Log::info('Start Date: ' . $startDate->toDateTimeString() . ', End Date: ' . $endDate->toDateTimeString());
                    $query->whereBetween('created_at', [$startDate, $endDate]);
                } else {
                    Log::warning('Invalid date range format: ' . $value);
                }
            } else {
                Log::warning('Date filter is not a string: ' . json_encode($value));
            }
        });

        $customWeekFilter = AllowedFilter::callback('week', function ($query, $value) {
            if (is_string($value)) {
                $date = Carbon::createFromFormat('Y-m-d', $value);
                $startOfWeek = $date->copy()->startOfWeek()->startOfDay();
                $endOfWeek = $date->copy()->endOfWeek()->endOfDay();
                Log::info('Week filter value: ' . $value);
                Log::info('Start of Week: ' . $startOfWeek->toDateTimeString() . ', End of Week: ' . $endOfWeek->toDateTimeString());
                $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
            } else {
                Log::warning('Week filter is not a string: ' . json_encode($value));
            }
        });

        $customMonthFilter = AllowedFilter::callback('month', function ($query, $value) {
            if (is_string($value)) {
                $date = Carbon::createFromFormat('Y-m', $value);
                $startOfMonth = $date->copy()->startOfMonth()->startOfDay();
                $endOfMonth = $date->copy()->endOfMonth()->endOfDay();
                Log::info('Month filter value: ' . $value);
                Log::info('Start of Month: ' . $startOfMonth->toDateTimeString() . ', End of Month: ' . $endOfMonth->toDateTimeString());
                $query->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
            } else {
                Log::warning('Month filter is not a string: ' . json_encode($value));
            }
        });

        // Pagination parameters
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 10);

        // Query to get orders with filters and pagination
        $ordersQuery = QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::exact('cashier_id'),
                AllowedFilter::exact('shop_id'),
                $customDateFilter,
                $customWeekFilter,
                $customMonthFilter,
            ])
            ->with(['user', 'shop', 'orderDetails' => function ($query) {
                $query->with(['item']);
            }])
            ->whereIn('status', ['done', 'cancel'])
            ->orderBy('created_at');

        // Paginate the results
        $orders = $ordersQuery->paginate($perPage, ['*'], 'page', $page);

        // Log the raw SQL query for debugging
        // Log::info('Generated Query: ' . $ordersQuery->toSql());

        return response()->json($orders);
    }

    public function getAllOrders(Request $request)
    {
        $orders = QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::partial('casier_id'),
                AllowedFilter::partial('shop_id'),
            ])
            ->with(['shop'])
            ->get();
        return response()->json($orders);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create() {}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'casier_id' => 'required|exists:users,id',
            'shop_id' => 'required|exists:shops,id',
            'total_selling_price' => 'required|numeric|min:0',
            'total_actual_price' => 'required|numeric|min:0',
            'status' => 'required',
            'comment' => 'nullable|string',
            'kot_id' => 'nullable',
            'payment_type' => 'required',
            'order_details' => 'required|array|min:1',
            'order_details.*.item_id' => 'required|exists:items,id',
            'order_details.*.type' => 'required|string',
            'order_details.*.neededAmount' => 'required|min:0',
            'order_details.*.num_of_items' => 'required|min:1',
            'order_details.*.total_price_per_units' => 'required|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Create the order
            $order = Order::create($request->only(['casier_id', 'shop_id', 'total_selling_price', 'comment',  'total_actual_price', 'status', 'payment_type', 'kot_id']));

            // Create the order details and update stock
            $orderDetails = $request->input('order_details');
            foreach ($orderDetails as $detail) {
                $detail['order_id'] = $order->id;
                OrderDetails::create($detail);
            }

            DB::commit();

            return response()->json($order->load('orderDetails.item'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create order: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        DB::beginTransaction();

        try {
            // Update the order
            $order->update($request->only(['casier_id', 'shop_id', 'total_price']));

            // Update or create order details
            $orderDetails = $request->input('order_details');
            foreach ($orderDetails as $detail) {
                OrderDetails::updateOrCreate(
                    ['id' => $detail['id'] ?? null],
                    [
                        'order_id' => $order->id,
                        'item_id' => $detail['item_id'],
                        'type' => $detail['type'],
                        'num_of_items' => $detail['num_of_items'],
                        'total_price_per_units' => $detail['total_price_per_units']
                    ]
                );
            }

            DB::commit();

            return response()->json($order->load('orderDetails.item'), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to update order: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        DB::beginTransaction();

        try {
            // Delete order details
            $order->orderDetails()->delete();

            // Delete order
            $order->delete();

            DB::commit();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to delete order: ' . $e->getMessage()], 500);
        }
    }

    public function getOrderDetails(Request $request)
    {
        $itemId = $request->input('item_id');  // Get the item_id from the request
        $itemName = $request->input('name');   // Get the item name from the request
        $shopId = $request->input('shop_id');  // Get the shop_id from the request
        $orderId = $request->input('order_id'); // Get the order_id from the request
        $limit = $request->input('limit', 30);  // Get the limit, default to 10 if not provided

        // Initialize the query with order and item relationships
        $query = OrderDetails::with(['order', 'item']);

        // Filter by shop_id if provided
        if ($shopId) {
            $query->whereHas('item', function ($q) use ($shopId) {
                $q->where('shop_id', $shopId);
            });
        }

        // Filter by item_id from the 'items' table
        if ($itemId) {
            $query->whereHas('item', function ($q) use ($itemId) {
                $q->where('item_id', $itemId);
            });
        }

        // Filter by partial item name
        if ($itemName) {
            $query->whereHas('item', function ($q) use ($itemName) {
                $q->where('name', 'like', '%' . $itemName . '%');
            });
        }

        // Filter by order_id from the 'order_details' table
        if ($orderId) {
            $query->where('order_id', $orderId);
        }

        // Apply the limit to the query
        $query->limit($limit);

        // Get the filtered results
        $orderDetails = $query->get();

        return response()->json($orderDetails);
    }

    public function getInprogressOrders(Request $request)
    {
        $shopId = $request->input('shop_id');  // Filter by shop_id if provided
        $orderId = $request->input('order_id'); // Filter by specific order_id
        $limit = $request->input('limit', 30);  // Default limit
        $perPage = $request->input('per_page', 20); // Default per page
        $page = $request->input('page', 1);

        // Initialize the query for Order with relationships
        $query = QueryBuilder::for(Order::class)
            ->with(['orderDetails.item']) // Load orderDetails and their related items
            ->where('status', 'inprogress'); // Filter by 'inprogress' status

        // Apply filter by shop_id through the 'item' relationship
        if ($shopId) {
            $query->whereHas('orderDetails.item', function ($q) use ($shopId) {
                $q->where('shop_id', $shopId);
            });
        }

        // Apply filter by order_id
        if ($orderId) {
            $query->where('kot_id', $orderId); // Assuming 'id' is the primary key for orders
        }

        // Apply pagination
        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        // Return JSON response with pagination
        return response()->json($orders);
    }



    public function updateOrder(Request $request, $orderId)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string',
            'comment' => 'nullable|string',
            'payment_type' => 'nullable|string',
            'total_selling_price' => 'nullable|numeric|min:0',

        ]);

        // Return validation errors if any
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find the order by the provided orderId
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        // Begin transaction
        DB::beginTransaction();

        try {
            // Update the order fields if provided
            if ($request->has('status')) {
                $order->status = $request->input('status');
            }

            if ($request->has('payment_type')) {
                $order->payment_type = $request->input('payment_type');
            }

            if ($request->has('total_selling_price')) {
                $order->total_selling_price = $request->input('total_selling_price');
            }

            if ($request->has('comment')) {
                $order->comment = $request->input('comment');
            }

            // Save the updated order
            $order->save();

            // Commit the transaction
            DB::commit();

            // Return the updated order with orderDetails and item
            return response()->json($order->load('orderDetails.item'), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update order: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function cancelOrder(Request $request, $orderId)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string'
        ]);

        // Return validation errors if any
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find the order by the provided orderId
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        // Begin transaction
        DB::beginTransaction();

        try {

            if ($request->has('comment')) {
                $order->comment = $request->input('comment');
                $order->status = 'cancel';
                $order->payment_type = 'cancel';
            }

            // Save the updated order
            $order->save();

            // Commit the transaction
            DB::commit();

            // Return the updated order with orderDetails and item
            return response()->json($order->load('orderDetails.item'), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update order: ' . $e->getMessage(),
            ], 500);
        }
    }



    public function returnedItem(Request $request)
    {
        try {
            // Find the specific order detail by ID
            $orderDetail = OrderDetails::find($request->order_id);

            // Check if the order detail exists
            if (!$orderDetail) {
                return response()->json(['error' => 'Order detail not found'], 404);
            }

            // Get the returned item count from the request
            $returnedCount = $request->input('returned_count', 0);

            // Compare the returned count with the sold item count
            if ($request->returned_count == $request->sold_item_count) {
                // If returned item count is equal or greater than sold item count, delete the order detail


                // Update the item's stock count if necessary
                $item = Item::find($request->item_id); // Assuming orderDetail has an item_id field
                if ($item) {
                    $item->increment('num_of_items', $request->sold_item_count); // Add back sold items to stock
                }

                $orderDetail->delete();

                return response()->json(['success' => true, 'message' => 'Order detail deleted successfully'], 200);
            } else {
                // If returned item count is less than sold item count, update the order detail
                $orderDetail->num_of_items -= $returnedCount; // Subtract the returned count
                $orderDetail->total_price_per_units -= $request->price_per_unit * $returnedCount; // Assuming you have price_per_unit field

                // Update the corresponding item count in the item table
                $item = Item::find($orderDetail->item_id);
                if ($item) {
                    $item->increment('num_of_items', $returnedCount); // Add returned items back to stock
                }

                // Save the changes
                $orderDetail->save();

                return response()->json(['success' => true, 'message' => 'Order detail updated successfully'], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to process the request: ' . $e->getMessage()], 500);
        }
    }

    public function getTodayDoneOrderDetails(Request $request)
    {
        // Define custom filters
        $customDateFilter = AllowedFilter::callback('date', function ($query, $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            if (is_string($value)) {
                Log::info('Date filter value: ' . $value);
                $dates = explode(',', $value);
                if (count($dates) == 2) {
                    $startDate = Carbon::parse($dates[0])->startOfDay();
                    $endDate = Carbon::parse($dates[1])->endOfDay();
                    Log::info('Start Date: ' . $startDate->toDateTimeString() . ', End Date: ' . $endDate->toDateTimeString());
                    $query->whereBetween('created_at', [$startDate, $endDate]);
                } else {
                    Log::warning('Invalid date range format: ' . $value);
                }
            } else {
                Log::warning('Date filter is not a string: ' . json_encode($value));
            }
        });

        $customWeekFilter = AllowedFilter::callback('week', function ($query, $value) {
            if (is_string($value)) {
                $date = Carbon::createFromFormat('Y-m-d', $value);
                $startOfWeek = $date->copy()->startOfWeek()->startOfDay();
                $endOfWeek = $date->copy()->endOfWeek()->endOfDay();
                Log::info('Week filter value: ' . $value);
                Log::info('Start of Week: ' . $startOfWeek->toDateTimeString() . ', End of Week: ' . $endOfWeek->toDateTimeString());
                $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
            } else {
                Log::warning('Week filter is not a string: ' . json_encode($value));
            }
        });

        $customMonthFilter = AllowedFilter::callback('month', function ($query, $value) {
            if (is_string($value)) {
                $date = Carbon::createFromFormat('Y-m', $value);
                $startOfMonth = $date->copy()->startOfMonth()->startOfDay();
                $endOfMonth = $date->copy()->endOfMonth()->endOfDay();
                Log::info('Month filter value: ' . $value);
                Log::info('Start of Month: ' . $startOfMonth->toDateTimeString() . ', End of Month: ' . $endOfMonth->toDateTimeString());
                $query->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
            } else {
                Log::warning('Month filter is not a string: ' . json_encode($value));
            }
        });

        // Query to get all orders with filters, no pagination
        $ordersQuery = QueryBuilder::for(Order::class)
            ->allowedFilters([
                AllowedFilter::exact('cashier_id'),
                AllowedFilter::exact('shop_id'),
                $customDateFilter,
                $customWeekFilter,
                $customMonthFilter,
            ])
            ->with(['user', 'shop', 'orderDetails' => function ($query) {
                $query->with(['item']);
            }])
            ->where('status', 'done') // Fetch both 'done' and 'cancel' statuses
            ->orderBy('created_at');

        // Get all results (without pagination)
        $orders = $ordersQuery->get();

        return response()->json($orders);
    }
}

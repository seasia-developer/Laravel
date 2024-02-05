<?php

namespace App\Http\Controllers\Backend;

use App\Filters\OrderFilters;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RandomAddress;
use App\Models\RandomShippingAddress;
use App\Models\Setting;
use App\Models\ShippingAddress;
use App\Models\Tid;
use App\Models\User;
use App\Models\UserOrder;
use App\Models\UserOrderNote;
use App\Services\AnalyticsService;
use App\Services\Domain\TidGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use setasign\Fpdi\Fpdi;
use Throwable;

class OrdersController extends Controller
{
    /** @var \App\Services\Domain\TidGenerationService */
    private $tidGenerationService;

    /** @var \App\Services\AnalyticsService */
    private $analyticsService;

    /**
     * OrdersController constructor.
     */
    public function __construct(TidGenerationService $tidGenerationService, AnalyticsService $analyticsService)
    {
        $this->middleware( 'backend' );
        $this->middleware( 'permission:manage_orders' );

        $this->tidGenerationService = $tidGenerationService;
        $this->analyticsService = $analyticsService;
    }


    /**
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deleteOrder($id)
    {
        UserOrder::where('id', $id)->delete();

        return redirect()->route('backend-orders');
    }


    /**
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function cancelOrder($id)
    {
        $order = UserOrder::where('id', $id)->get()->first();

        if ($order != null) {
            $order->update([
                'status' => 'cancelled'
            ]);

            $user = User::where('id', $order->user_id)->get()->first();

            if ($user != null) {
                $newBalance = $user->balance_in_cent + ($order->price_in_cent + $order->delivery_price);

                $user->update([
                    'balance_in_cent' => $newBalance
                ]);
            }
        }

        return redirect()->route('backend-orders');
    }


    /**
     * @param $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function addNote($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_note' => 'required|max:500'
        ]);

        if ($validator->passes()) {
            $noteText = $request->input('order_note');

            UserOrderNote::create([
                'order_id' => $id,
                'note' => $noteText
            ]);

            return redirect()->route('backend-order-id', ['id' => $id])->with([
                'successMessage' => __('backend/main.added_successfully')
            ]);
        }

        $request->flash();
        return redirect()->route('backend-order-id', ['id' => $id])->withErrors($validator)->withInput();
    }

    /**
     * @param $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function addLabelCode($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'label_code' => 'required|max:100'
        ]);

        if ($validator->passes()) {
            $order = UserOrder::query()->findOrFail($id);
            $order->update([
                'label_code' => $request->input('label_code'),
//                'status' => 'completed'
            ]);

            return redirect()->route('backend-order-id', ['id' => $id])->with([
                'successMessage' => __('backend/main.added_successfully')
            ]);
        }

        $request->flash();
        return redirect()->route('backend-order-id', ['id' => $id])->withErrors($validator)->withInput();
    }

    /**
     * @param $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function uploadPdf($id, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf'
        ]);

        if ($validator->passes()) {

            return redirect()->route('backend-order-id', ['id' => $id])->with([
                'successMessage' => __('backend/main.added_successfully')
            ]);
        }

        $request->flash();
        return redirect()->route('backend-order-id', ['id' => $id])->withErrors($validator)->withInput();
    }


    /**
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function completeOrder($id)
    {
        $order = UserOrder::where('id', $id)->first();

        if ($order != null) {
            $order->update([
                'status' => 'completed'
            ]);
        }

        return redirect()->route('backend-orders');
    }


    /**
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function showOrder($id)
    {
        $order = UserOrder::with(['products', 'address'])->where('id', $id)->first();
        if (!$order) {
            return redirect()->route('not-found');
        }

        $notes = UserOrderNote::orderByDesc('created_at')->where('order_id', $id)->get();
        $product = $order->products;
        $isBoxingProduct = in_array(strtolower($product->name), ['nachnahme boxing']);

        $shipping = Setting::where('key', 'like', 'shipping%')->get();

        $settings = [];

        foreach ($shipping->pluck('value', 'key')->toArray() as $key => $setting)
            $settings[explode('.', $key)[1]] = $setting;

        $settings = (object)$settings;

        if ($order != null) {
            return view('backend.orders.show', compact('order', 'notes', 'product', 'settings', 'isBoxingProduct'));
        }

        return redirect()->route('backend-orders');
    }


    public function setStatus($id, Request $request)
    {
        UserOrder::find($id)->update(['status' => $request->status]);

        return back();
    }

    public function setBulkStatus(Request $request)
    {
        $ids = explode(',', $request->orderids);
        UserOrder::whereIn('id', $ids)->update(['status' => $request->status]);

        return back();
    }

    public function setBoxingStatus($id, Request $request)
    {
        UserOrder::find($id)->update(['boxing_status' => $request->status]);

        return back();
    }

    public function setReplaceStatus($id, Request $request)
    {
        UserOrder::find($id)->update(['replace_status' => $request->status]);

        return back();
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clearFilter(Request $request)
    {
        $request->flashOnly([
            'typeFilter',
            'statusFilter',
            'dateFilter'
        ]);

        return redirect()->route('backend-orders-with-pageNumber', 1);
    }


    /**
     * @param int $pageNumber
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function showOrdersPage($pageNumber = 0, Request $request, OrderFilters $filters)
    {
        $term = $request->query('term');

        if ($request->address_not_changed) {

            $orders_packing_station = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product
                        ->whereNotIn('name', [
                            '[DE] Originale 80% Fake-TID',
                            '[EU] Originale 80% Fake-TID',
                            'LIT für Filling',
                            'LIT für Refund'
                        ])
                        ->where('name', 'not like', '%100\%%');
                })
                ->with(['products', 'tids', 'address', 'user'])
                ->where('users_orders.delivery_name', 'Packstation')
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_packing_station', $pageNumber)->setPath(route('backend-orders'));

        } else {

            $orders_packing_station = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product
                        ->whereNotIn('name', [
                            '[DE] Originale 80% Fake-TID',
                            '[EU] Originale 80% Fake-TID',
                            'LIT für Filling',
                            'LIT für Refund'
                        ])
                        ->where('name', 'not like', '%100\%%');
                })
                ->with(['products', 'tids', 'address', 'user'])
                ->where('delivery_name', 'Packstation')
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_packing_station', $pageNumber)->setPath(route('backend-orders'));

        }
        if (
            $request->page_packing_station > $orders_packing_station->lastPage() ||
            $request->input('page_packing_station', 1) <= 0
        ) {
            $request->merge(['page_packing_station' => 1]);

            return redirect()->route('backend-orders')->withInput();
        }


        if ($request->address_not_changed) {
            $orders_branch_delivery = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product
                        ->whereNotIn('name', [
                            '[DE] Originale 80% Fake-TID',
                            '[EU] Originale 80% Fake-TID',
                            'LIT für Filling',
                            'LIT für Refund'
                        ])
                        ->where('name', 'not like', '%100\%%');
                })
                ->with(['products', 'tids', 'address', 'user'])
                ->where('users_orders.delivery_name', 'Filialeinlieferung')
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));

        } else {
            $orders_branch_delivery = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product
                        ->whereNotIn('name', [
                            '[DE] Originale 80% Fake-TID',
                            '[EU] Originale 80% Fake-TID',
                            'LIT für Filling',
                            'LIT für Refund'
                        ])
                        ->where('name', 'not like', '%100\%%');
                })
                ->with(['products', 'tids', 'address', 'user'])
                ->where('delivery_name', 'Filialeinlieferung')
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            // ->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        }

        if ($request->address_not_changed) {
            $ordersLitRefund = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'LIT für Refund');
                })
                ->with(['products', 'tids', 'user','random_shipping_address'])
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        } else {
            $ordersLitRefund = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'LIT für Refund');
                })
                ->with(['products', 'tids', 'user','random_shipping_address'])
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        }

        if ($request->address_not_changed) {
            $ordersLitFilling = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product
                        ->where('name', 'LIT für Filling');
                })
                ->with(['products', 'tids', 'address', 'user'])
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        } else {
            $ordersLitFilling = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'LIT für Filling');
                })
                ->with(['products', 'tids', 'address', 'user'])
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        }

        if ($request->address_not_changed) {
            $ordersRandom = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'LIT für Filling')
                        ->orWhere('name', 'LIT für Refund');
                })
                ->with(['products', 'tids', 'user', 'random_shipping_address'])
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                //->paginate(10)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
        } else {
            $ordersRandom = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'LIT für Filling')
                        ->orWhere('name', 'LIT für Refund');
                })
                ->with(['products', 'tids', 'user', 'random_shipping_address'])
                ->orderByDesc('id')
                //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
        }

        if ($request->address_not_changed) {
            $ordersBoxing = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'Nachnahme Boxing');
                })
                ->with(['products', 'tids', 'user'])
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        } else {
            $ordersBoxing = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'Nachnahme Boxing');
                })
                ->with(['products', 'tids', 'user'])
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        }

        if ($request->address_not_changed) {
            $ordersAccounts = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product->whereHas('category', function ($category) {
                        $category->where('is_digital_goods', 1)
                            ->whereNotIn('name', [
                                '[DE] Originale 80% Fake-TID',
                                '[EU] Originale 80% Fake-TID'
                            ])->where('name', 'not like', '%100\%%');
                    });
                })
                ->with(['products', 'tids', 'user','shipping_address'])
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        } else {
            $ordersAccounts = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product->whereHas('category', function ($category) {
                        $category->where('is_digital_goods', 1)
                            ->whereNotIn('name', [
                                '[DE] Originale 80% Fake-TID',
                                '[EU] Originale 80% Fake-TID'
                            ])
                            ->where('name', 'not like', '%100\%%');
                    });
                })
                ->with(['products', 'tids', 'user','shipping_address'])
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        }

        if ($request->address_not_changed) {
            $orders100percents = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'like', '%100\%%');
                })
                ->with(['products', 'user'])
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        } else {
            $orders100percents = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product->where('name', 'like', '%100\%%');
                })
                ->with(['products', 'user'])
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        }

        if ($request->address_not_changed) {
            $orders80percents = UserOrder::join('shipping_address', 'users_orders.id', '=', 'shipping_address.order_id')
                ->whereHas('products', function ($product) {
                    return $product->whereIn('name', [
                        '[DE] Originale 80% Fake-TID',
                        '[EU] Originale 80% Fake-TID'
                    ]);
                })
                ->with(['products', 'user'])
                ->where('shipping_address.recipient_first_name', null)
                ->orderByDesc('users_orders.id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        } else {
            $orders80percents = UserOrder::filter($filters)
                ->whereHas('products', function ($product) {
                    return $product->whereIn('name', [
                        '[DE] Originale 80% Fake-TID',
                        '[EU] Originale 80% Fake-TID'
                    ]);
                })
                ->with(['products', 'user'])
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page_branch_delivery', $pageNumber)->setPath(route('backend-orders'));
            //->paginate(25)->setPath(route('backend-orders'))->setPageName('page_branch_delivery');
        }

        if (
            $request->page_branch_delivery > $orders_branch_delivery->lastPage() ||
            $request->input('page_branch_delivery', 1) <= 0
        ) {
            $request->merge(['page_branch_delivery' => 1]);

            return redirect()->route('backend-orders')->withInput();
        }


        // if ( $pageNumber > $orders_branch_delivery->lastPage() || $pageNumber <= 0 )
        //     return redirect()->route( 'backend-orders-with-pageNumber', 1 );

        $branch_delivery_count = $this->analyticsService->getOrdersCountByDeliveryMethod('Filialeinlieferung');
        $packing_station_count = $this->analyticsService->getOrdersCountByDeliveryMethod('Packstation');
        $ordersLitFillingCount = $this->analyticsService->getProductOrdersCount('LIT für Filling', true);
        $ordersLitRefundCount = $this->analyticsService->getProductOrdersCount('LIT für Refund', true);
        $ordersBoxingCount = $this->analyticsService->getProductOrdersCount('Nachnahme Boxing', true);


        return view('backend.orders.list', compact(
            'term',
            'orders_packing_station',
            'orders_branch_delivery',
            'branch_delivery_count',
            'packing_station_count',
            'ordersLitRefund',
            'ordersLitFilling',
            'ordersRandom',
            'ordersAccounts',
            'orders100percents',
            'orders80percents',
            'ordersBoxing',
            'ordersLitFillingCount',
            'ordersLitRefundCount',
            'ordersBoxingCount'
        ));
    }


    /**
     * @param $query
     * @return string
     */
    public static function getSqlWithBindings($query)
    {
        return vsprintf(str_replace('?', '%s', $query->toSql()), collect($query->getBindings())->map(function ($binding) {
            return is_numeric($binding) ? $binding : "'{$binding}'";
        })->toArray());
    }

    /**
     * @param $orderId
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function uploadTidFileManual($orderId, Request $request)
    {
        $this->validate($request, [
            'file' => 'required|mimes:pdf'
        ]);
        $file = $request->file('file');

        $file->move("storage/order_tid", $orderId . '.' . $file->getClientOriginalExtension());
        return redirect()->back()->with('successMessage', 'TID file has been successfully uploaded');
    }


    /**
     * @param $orderId
     * @return \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadTidFileManual($orderId)
    {
        if (!Storage::disk('public')->exists("order_tid/{$orderId}.pdf")) {
            return redirect()->back();
        }

        $path = Storage::disk('public')->path("order_tid/{$orderId}.pdf");
        return response()->download($path);
    }

    public function downloadTidFile($orderId, Request $request)
    {
        $order = UserOrder::find($orderId);
        $original_name = $order->tids->tid;
        if (Storage::disk('public')->exists("order/{$order->id}")) {
            Storage::disk('public')->deleteDirectory("order/{$order->id}");
            //$this->createTidFile($order->id);
            // return redirect()->route('not-found');
        }
        $this->tidGenerationService->generateTidPDF($order->id);
        $path = Storage::disk('public')->path("order/{$order->id}/$original_name");

        return response()->download($path);
    }

    public function downloadTidFileRandom($order_id, Request $request)
    {
        $order = UserOrder::find($order_id);
        if (!$order instanceof UserOrder || is_null($order->random_tid)) {
            return redirect()->route('not-found');
        }

        $original_name = $order->random_tid;
        $path = Storage::disk('public')->path("order/{$order->id}/{$original_name}");

        if (!Storage::disk('public')->exists("order/{$order->id}/{$original_name}")) {
            return redirect()->route('not-found');
        }

        return response()->download($path);
    }

    public function recipientEdit($orderId)
    {
        $recipient_address = ShippingAddress::where('order_id', $orderId)->first();
        if (is_null($recipient_address)) {
            $recipient_address = new ShippingAddress();
        }

        return view('backend.orders.edit', compact('recipient_address', 'orderId'));
    }

    public function recipientUpdate($orderId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient_first_name' => 'required|max:255',
            'recipient_last_name' => 'required|max:255',
            'recipient_street' => 'required|max:255',
            'recipient_zip' => 'required|max:255',
            'recipient_city' => 'required|max:255',
            'recipient_country' => 'required|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        ShippingAddress::updateOrCreate(
            [
                'order_id' => $orderId,
            ],
            [
                'recipient_first_name' => $request->recipient_first_name,
                'recipient_last_name' => $request->recipient_last_name,
                'recipient_street' => $request->recipient_street,
                'recipient_zip' => $request->recipient_zip,
                'recipient_city' => $request->recipient_city,
                'recipient_country' => $request->recipient_country,
            ]
        );

        $this->tidGenerationService->generateTidPDF($orderId);

        return redirect('/admin/orders');
    }

    public function randomRecipientEdit($orderId)
    {
        $order = UserOrder::find($orderId);
        if (!$order instanceof UserOrder) {
            return redirect()
                ->route('not-found');
        }

        $recipientAddress = RandomShippingAddress::where('order_id', $orderId)->first();
        if (!$recipientAddress instanceof RandomShippingAddress) {
            $recipientAddress = ShippingAddress::where('order_id', $orderId)->first();
        }

        if (is_null($recipientAddress)) {
            $recipientAddress = new RandomShippingAddress();
        }

        return view(
            'backend.orders.edit_random',
            [
                'recipientAddress' => $recipientAddress,
                'orderId' => $orderId,
            ]
        );
    }

    public function randomRecipientUpdate($orderId, Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'sender_first_name' => 'required|max:255',
                'sender_last_name' => 'required|max:255',
                'sender_street' => 'required|max:255',
                'sender_zip' => 'required|max:255',
                'sender_city' => 'required|max:255',
                'sender_country' => 'required|max:255',
                'recipient_first_name' => 'required|max:255',
                'recipient_last_name' => 'required|max:255',
                'recipient_street' => 'required|max:255',
                'recipient_zip' => 'required|max:255',
                'recipient_city' => 'required|max:255',
                'recipient_country' => 'required|max:255',
            ]
        );

        if ($validator->fails()) {
            return redirect()
                ->back()
                ->withErrors($validator)
                ->withInput();
        }

        $order = UserOrder::find($orderId);
        if (!$order instanceof UserOrder) {
            return redirect()
                ->route('not-found');
        }

        $randomShippingAddress = RandomShippingAddress::updateOrCreate(
            [
                'order_id' => $orderId,
            ],
            [
                'sender_first_name' => $request->sender_first_name,
                'sender_last_name' => $request->sender_last_name,
                'sender_street' => $request->sender_street,
                'sender_zip' => $request->sender_zip,
                'sender_city' => $request->sender_city,
                'sender_country' => $request->sender_country,
                'recipient_first_name' => $request->recipient_first_name,
                'recipient_last_name' => $request->recipient_last_name,
                'recipient_street' => $request->recipient_street,
                'recipient_zip' => $request->recipient_zip,
                'recipient_city' => $request->recipient_city,
                'recipient_country' => $request->recipient_country,
            ]
        );

        try {
            $this->tidGenerationService->generateRandomTidPDF($orderId, $randomShippingAddress);
        } catch (Throwable $e) {
            return false;
        }

        return redirect('/admin/orders');
    }

    public function randomRecipientAddress($orderId)
    {
        $order = UserOrder::find($orderId);
        if (!$order instanceof UserOrder) {
            return redirect()
                ->route('not-found');
        }

        $randomAddress = RandomAddress::inRandomOrder()->first();
        if (!$randomAddress instanceof RandomAddress) {
            return redirect()
                ->back()
                ->with([
                    'errorMessage' => 'Kein Eintrag in der Adressdatenbank gefunden!'
                ]);
        }

        try {
            DB::beginTransaction();

            $randomShippingAddress = RandomShippingAddress::updateOrCreate(
                [
                    'order_id' => $orderId,
                ],
                [
                    'first_name' => $randomAddress->first_name ?? null,
                    'last_name' => $randomAddress->last_name ?? null,
                    'street' => $randomAddress->street ?? null,
                    'zip' => $randomAddress->zip ?? null,
                    'city' => $randomAddress->city ?? null,
                    'country' => $randomAddress->country ?? 'Deutschland',
                    'sender_first_name' => $randomAddress->sender_first_name ?? null,
                    'sender_last_name' => $randomAddress->sender_last_name ?? null,
                    'sender_street' => $randomAddress->sender_street ?? null,
                    'sender_zip' => $randomAddress->sender_zip ?? null,
                    'sender_city' => $randomAddress->sender_city ?? null,
                    'sender_country' => $randomAddress->sender_country ?? 'Deutschland',
                    'recipient_first_name' => $randomAddress->recipient_first_name ?? null,
                    'recipient_last_name' => $randomAddress->recipient_last_name ?? null,
                    'recipient_street' => $randomAddress->recipient_street ?? null,
                    'recipient_zip' => $randomAddress->recipient_zip ?? null,
                    'recipient_city' => $randomAddress->recipient_city ?? null,
                    'recipient_country' => $randomAddress->recipient_country ?? 'Deutschland',
                ]
            );

            DB::commit();

            DB::beginTransaction();

            $randomAddress->delete();

            DB::commit();

            $this->tidGenerationService->generateRandomTidPDF($orderId, $randomShippingAddress);
        } catch (Throwable $e) {
            DB::rollBack();
        }

        return redirect()->route('backend-order-edit-random', ['orderId' => $orderId]);
    }

    public function createTidFile($order_id)
    {
        $order = UserOrder::find($order_id);
        $original_name = $order->tids->tid;
        $file_loc = $order->tids->loc;
        $offset_x = 170;
        $offset_y = 30;
        if ($file_loc == 'eu') {
            $offset_x = 35;
            $offset_y = 22;
        }
        if (Storage::disk('public')->exists("tid_copy/$order->product_id/$original_name")) {
            Storage::disk('public')->delete("tid_copy/$order->product_id/$original_name");
        }

        if (Storage::disk('public')->exists("tid/$order->product_id/$original_name")) {
            Storage::disk('public')->copy("tid/$order->product_id/$original_name", "tid_copy/$order->product_id/$original_name");
        }

        $path = Storage::disk('public')->path("tid_copy/$order->product_id/$original_name");
        $pdf = new Fpdi();
        $pdf->setSourceFile($path);
        $tplIdx = $pdf->importPage(1);
        $specs = $pdf->getTemplateSize($tplIdx);
        $pdf->AddPage($specs['height'] > $specs['width'] ? 'P' : 'L');
        $pdf->useTemplate($tplIdx);

        $pdf->SetFont('arial', '', '10');
        $pdf->SetTextColor(0, 0, 0);

        $order = UserOrder::find($order_id);

        setlocale(LC_ALL, 'de_DE');

        $shipping = Setting::where('key', 'like', 'shipping%')->get();

        $settings = [];

        foreach ($shipping->pluck('value', 'key')->toArray() as $key => $setting)
            $settings[explode('.', $key)[1]] = $setting;


        // sender_first_name sender_last_name
        $pdf->SetXY($offset_x, $offset_y);
        $pdf->Write(0, $this->codeToISO(
            $order->address->sender_first_name . ' ' . $order->address->sender_last_name
        ));

        // sender_street
        $pdf->SetXY($offset_x, $offset_y + 5);
        $pdf->Write(0, $this->codeToISO($order->address->sender_street));

        // sender_zip
        $pdf->SetXY($offset_x, $offset_y + 10);
        $pdf->Write(0, $this->codeToISO($order->address->sender_zip));

        // sender_city
        $pdf->SetXY($offset_x, $offset_y + 15);
        $pdf->Write(0, $this->codeToISO($order->address->sender_city));

        // sender_country
        $pdf->SetXY($offset_x, $offset_y + 20);
        $pdf->Write(0, $this->codeToISO($order->address->sender_country));


        // first_name last_name
        $pdf->SetXY($offset_x, $offset_y + 30);
        $pdf->Write(0, $this->codeToISO($order->address->recipient_first_name . ' ' . $order->address->recipient_last_name));

        // street
        $pdf->SetXY($offset_x, $offset_y + 35);
        $pdf->Write(0, $this->codeToISO($order->address->recipient_street));

        // zip
        $pdf->SetXY($offset_x, $offset_y + 40);
        $pdf->Write(0, $this->codeToISO($order->address->recipient_zip));

        // city
        $pdf->SetXY($offset_x, $offset_y + 45);
        $pdf->Write(0, $this->codeToISO($order->address->recipient_city));

        // country
        $pdf->SetXY($offset_x, $offset_y + 50);
        $pdf->Write(0, $this->codeToISO($order->address->recipient_country));


        $path = "order/$order_id";

        Storage::disk('public')->makeDirectory($path);

        if (is_file(public_path("storage/order/{$order->id}/{$original_name}"))) {
            unlink(public_path("storage/order/{$order->id}/{$original_name}"));
        }

        $pdf->Output(public_path("storage/order/$order_id/$original_name"), 'F');

        if (Storage::disk('public')->exists("tid_copy/$order->product_id/$original_name")) {
            Storage::disk('public')->delete("tid_copy/$order->product_id/$original_name");
        }
        return back()->with('success', 'You have successfully upload file.');
    }

    public function createRandomTidFile($order_id, $address = null)
    {
        $order = UserOrder::find($order_id);
        $product = Product::where('name', 'Random')->first();
        if (!$order instanceof UserOrder || is_null($address) || !$product instanceof Product) {
            return;
        }

        $tid = Tid::where('product_id', $product->id)->where('used', 0)->firstOrFail();
        $tid->update(['used' => 1]);

        $order->update([
            'random_tid' => $tid->tid
        ]);

        $original_name = $tid->tid;
        $file_loc = $tid->loc;

        $offset_x = 170;
        $offset_y = 30;
        if ($file_loc == 'eu') {
            $offset_x = 35;
            $offset_y = 22;
        }

        Storage::disk('public')->delete("tid_copy/$product->id/$original_name");
        Storage::disk('public')->copy("tid/$product->id/$original_name", "tid_copy/$product->id/$original_name");
        $path = Storage::disk('public')->path("tid_copy/$product->id/$original_name");

        $pdf = new Fpdi();
        $pdf->setSourceFile($path);

        $tplIdx = $pdf->importPage(1);
        $specs = $pdf->getTemplateSize($tplIdx);
        $pdf->AddPage($specs['height'] > $specs['width'] ? 'P' : 'L');
        $pdf->useTemplate($tplIdx);

        $pdf->SetFont('arial', '', '10');
        $pdf->SetTextColor(0, 0, 0);

        setlocale(LC_ALL, 'de_DE');

        $shipping = Setting::where('key', 'like', 'shipping%')->get();

        $settings = [];

        foreach ($shipping->pluck('value', 'key')->toArray() as $key => $setting)
            $settings[explode('.', $key)[1]] = $setting;

        // sender_first_name sender_last_name
        $pdf->SetXY($offset_x, $offset_y);
        $pdf->Write(0, $this->codeToISO(($address->sender_first_name ?? '') . ' ' . ($address->sender_last_name ?? '')));


        // sender_street
        $pdf->SetXY($offset_x, $offset_y + 5);
        $pdf->Write(0, $this->codeToISO($address->sender_street));

        // sender_zip
        $pdf->SetXY($offset_x, $offset_y + 10);
        $pdf->Write(0, $this->codeToISO($address->sender_zip));

        // sender_city
        $pdf->SetXY($offset_x, $offset_y + 15);
        $pdf->Write(0, $this->codeToISO($address->sender_city));

        // sender_country
        $pdf->SetXY($offset_x, $offset_y + 20);
        $pdf->Write(0, $this->codeToISO($address->sender_country));

        // first_name last_name
        $pdf->SetXY($offset_x, $offset_y + 30);
        $pdf->Write(0, $this->codeToISO(($address->recipient_first_name ?? '') . ' ' . ($address->recipient_last_name ?? '')));

        // street
        $pdf->SetXY($offset_x, $offset_y + 35);
        $pdf->Write(0, $this->codeToISO($address->recipient_street));

        // zip
        $pdf->SetXY($offset_x, $offset_y + 40);
        $pdf->Write(0, $this->codeToISO($address->recipient_zip));

        // city
        $pdf->SetXY($offset_x, $offset_y + 45);
        $pdf->Write(0, $this->codeToISO($address->recipient_city));

        // country
        $pdf->SetXY($offset_x, $offset_y + 50);
        $pdf->Write(0, $this->codeToISO($address->recipient_country));


        $path = "order/{$order->id}";

        Storage::disk("public")->makeDirectory($path);

        if (is_file(public_path("storage/order/{$order->id}/{$original_name}"))) {
            unlink(public_path("storage/order/{$order->id}/{$original_name}"));
        }

        $pdf->Output(public_path("storage/order/{$order->id}/{$tid->tid}"), 'F');

        Storage::disk('public')->delete("tid_copy/$product->id/$original_name");

        return back()->with('success', 'You have successfully upload file.');
    }

    public function codeToISO($str)
    {
        return iconv('UTF-8', 'ISO-8859-1', $str);
    }

    public function showAddressDatabasePage()
    {
        $count = RandomAddress::query()->count();
        $addresses = RandomAddress::query()->get();
        $addressesInline = "";

        foreach ($addresses as $address) {
            $addressesInline .= sprintf(
                "%s %s %s %s %s%s%s %s %s %s %s",
                $address->first_name,
                $address->last_name,
                $address->street,
                $address->zip,
                $address->city,
                PHP_EOL,
                $address->recipient_first_name,
                $address->recipient_last_name,
                $address->recipient_street,
                $address->recipient_zip,
                $address->recipient_city
            );
        }

        return view(
            'backend.orders.random_address_database',
            [
                'count' => $count,
                'addressesInline' => $addressesInline
            ]
        );
    }

    public function regenerateOrderPdf($orderId)
    {
        $order = UserOrder::find($orderId);
        //$tid = Tid::where( 'product_id', '=' , 16)->where(['used' => '0'])->firstOrFail();
        //$order->update(['tid_id' => $tid->id]);
        //$tid->update(['used' => 1]);

        return response()->json(['order' => $order]);
    }

    public function regenerateOrderPdf55($orderId)
    {
        try {
            $order = UserOrder::with(['address'])->find($orderId);
            if (!$order instanceof UserOrder) {
                return false;
            }

            $original_name = $order->tids->tid;
            $file_loc = $order->tids->loc;
            $offset_x = 170;
            $offset_y = 30;
            if ($file_loc == 'eu') {
                $offset_x = 35;
                $offset_y = 22;
            }
            if (Storage::disk('public')->exists("tid_copy/$order->product_id/$original_name")) {
                Storage::disk('public')->delete("tid_copy/$order->product_id/$original_name");
            }

            if (Storage::disk('public')->exists("tid/$order->product_id/$original_name")) {
                Storage::disk('public')->copy("tid/$order->product_id/$original_name", "tid_copy/$order->product_id/$original_name");
            }


            $path = Storage::disk('public')->path("tid_copy/$order->product_id/$original_name");


            $pdf = new Fpdi();
            $pdf->setSourceFile($path);
            $tplIdx = $pdf->importPage(1);
            $specs = $pdf->getTemplateSize($tplIdx);
            $pdf->AddPage($specs['height'] > $specs['width'] ? 'P' : 'L');
            $pdf->useTemplate($tplIdx);

            $pdf->SetFont('arial', '', '10');
            $pdf->SetTextColor(0, 0, 0);

            setlocale(LC_ALL, 'de_DE');

            $shipping = Setting::where('key', 'like', 'shipping%')->get();

            $settings = [];

            foreach ($shipping->pluck('value', 'key')->toArray() as $key => $setting) {
                $settings[explode('.', $key)[1]] = $setting;
            }
            // sender_first_name sender_last_name
            $pdf->SetXY($offset_x, $offset_y);
            $pdf->Write(0, $this->codeToISO(
                $order->address->sender_first_name . ' ' . $order->address->sender_last_name
            ));

            // sender_street
            $pdf->SetXY($offset_x, $offset_y + 5);
            $pdf->Write(0, $this->codeToISO($order->address->sender_street));

            // sender_zip
            $pdf->SetXY($offset_x, $offset_y + 10);
            $pdf->Write(0, $this->codeToISO($order->address->sender_zip));

            // sender_city
            $pdf->SetXY($offset_x, $offset_y + 15);
            $pdf->Write(0, $this->codeToISO($order->address->sender_city));

            // sender_country
            $pdf->SetXY($offset_x, $offset_y + 20);
            $pdf->Write(0, $this->codeToISO($order->address->sender_country));
            if ($order->address->recipient_first_name && $order->address->recipient_last_name && $order->address->recipient_street) {
                // first_name last_name
                $pdf->SetXY($offset_x, $offset_y + 30);
                $pdf->Write(0, $this->codeToISO($order->address->recipient_first_name . ' ' . $order->address->recipient_last_name));

                // street
                $pdf->SetXY($offset_x, $offset_y + 35);
                $pdf->Write(0, $this->codeToISO($order->address->recipient_street));

                // zip
                $pdf->SetXY($offset_x, $offset_y + 40);
                $pdf->Write(0, $this->codeToISO($order->address->recipient_zip));

                // city
                $pdf->SetXY($offset_x, $offset_y + 45);
                $pdf->Write(0, $this->codeToISO($order->address->recipient_city));

                // country
                $pdf->SetXY($offset_x, $offset_y + 50);
                $pdf->Write(0, $this->codeToISO($order->address->recipient_country));


            } else {
                // first_name last_name
                $pdf->SetXY($offset_x, $offset_y + 30);
                $pdf->Write(0, $this->codeToISO($order->address->first_name . ' ' . $order->address->last_name));

                // street
                $pdf->SetXY($offset_x, $offset_y + 35);
                $pdf->Write(0, $this->codeToISO($order->address->street));

                // zip
                $pdf->SetXY($offset_x, $offset_y + 40);
                $pdf->Write(0, $this->codeToISO($order->address->zip));

                // city
                $pdf->SetXY($offset_x, $offset_y + 45);
                $pdf->Write(0, $this->codeToISO($order->address->city));

                // country
                $pdf->SetXY($offset_x, $offset_y + 50);
                $pdf->Write(0, $this->codeToISO($order->address->country));

            }
            if (!is_null($order->products) && $order->products->name === 'LIT für Filling') {
                $pdf->image(__DIR__ . "/scan.png", 15, (($file_loc === 'eu') ? 22 : 30), 100, 36);
            }

            $path = "order/{$order->id}";

            Storage::disk("public")->makeDirectory($path);

            if (is_file(public_path("storage/order/{$order->id}/{$original_name}"))) {
                unlink(public_path("storage/order/{$order->id}/{$original_name}"));
            }

            $pdf->Output(public_path("storage/order/{$order->id}/{$original_name}"), 'F');

            if (Storage::disk('public')->exists("tid_copy/$order->product_id/$original_name")) {
                Storage::disk('public')->delete("tid_copy/$order->product_id/$original_name");
            }

            return response()->json(['success' => 'pdf generated successfully']);
        } catch (Throwable $e) {
            return response()->json(['error' => 'pdf failed']);
        }
    }


    public function addressDatabaseImport(Request $request)
    {
        $validator = Validator::make(
            $request->input(),
            [
                'addresses' => 'required|min:1'
            ]
        );

        if ($validator->fails()) {
            return redirect()
                ->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            DB::beginTransaction();

            $addresses = explode(PHP_EOL, $request->input('addresses') ?? '');

            if (count($addresses) > 0) {
                RandomAddress::query()->delete();
            }

            for ($i = 0; $i < count($addresses); $i++) {
                $preparedAddress = [];

                if (isset($addresses[$i])) {
                    $addressParts = explode(' ', $addresses[$i] ?? '');

                    if (count($addressParts) === 4) {
                        $preparedAddress = array_merge($preparedAddress, [
                            'first_name' => $addressParts[0] ?? null,
                            'last_name' => $addressParts[1] ?? null,
                            'zip' => $addressParts[2] ?? null,
                            'city' => $addressParts[3] ?? null,
                            'sender_first_name' => $addressParts[0] ?? null,
                            'sender_last_name' => $addressParts[1] ?? null,
                            'sender_zip' => $addressParts[2] ?? null,
                            'sender_city' => $addressParts[3] ?? null,
                            'recipient_country' => 'Deutschland'
                        ]);
                    } else {
                        $preparedAddress = array_merge($preparedAddress, [
                            'first_name' => $addressParts[0] ?? null,
                            'last_name' => $addressParts[1] ?? null,
                            'street' => ($addressParts[2] ?? null) . ' ' . ($addressParts[3] ?? null),
                            'zip' => $addressParts[4] ?? null,
                            'city' => $addressParts[5] ?? null,
                            'sender_first_name' => $addressParts[0] ?? null,
                            'sender_last_name' => $addressParts[1] ?? null,
                            'sender_street' => ($addressParts[2] ?? null) . ' ' . ($addressParts[3] ?? null),
                            'sender_zip' => $addressParts[4] ?? null,
                            'sender_city' => $addressParts[5] ?? null,
                            'recipient_country' => 'Deutschland'
                        ]);
                    }
                }

                if (isset($addresses[++$i])) {
                    $addressParts = explode(' ', $addresses[$i] ?? '');

                    if (count($addressParts) === 4) {
                        $preparedAddress = array_merge($preparedAddress, [
                            'recipient_first_name' => $addressParts[0] ?? null,
                            'recipient_last_name' => $addressParts[1] ?? null,
                            'recipient_zip' => $addressParts[2] ?? null,
                            'recipient_city' => $addressParts[3] ?? null,
                            'recipient_country' => 'Deutschland'
                        ]);
                    } else {
                        $preparedAddress = array_merge($preparedAddress, [
                            'recipient_first_name' => $addressParts[0] ?? null,
                            'recipient_last_name' => $addressParts[1] ?? null,
                            'recipient_street' => ($addressParts[2] ?? null) . ' ' . ($addressParts[3] ?? null),
                            'recipient_zip' => $addressParts[4] ?? null,
                            'recipient_city' => $addressParts[5] ?? null,
                            'recipient_country' => 'Deutschland'
                        ]);
                    }
                }

                if (count($preparedAddress) > 0) {
                    RandomAddress::create($preparedAddress);
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return redirect()
                ->back()
                ->with([
                    'errorMessage' => 'Kein Eintrag in der Adressdatenbank gefunden!'
                ]);
        }

        return redirect()
            ->back()
            ->with([
                'successMessage' => __('backend/main.added_successfully')
            ]);
    }
}

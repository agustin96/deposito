<?php

namespace App\Http\Controllers;

use App\Order;
use App\OrderDetail;
use App\OrderStatus;
use App\Http\Repositories\OrderRepository;
use App\Mail\NewOrder;
use App\Mail\OrderConfirmed;
use App\Mail\SolicitudPedidoMail;
use App\OrderPayForm;
use App\Stock;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        $this->middleware('is.administrator')->only(['edit', 'update', 'destroy']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request, OrderRepository $repository)
    {
        $orders = $repository->getOrders($request);

        $users = User::all();

        $user = ($request['user_id']) ? User::find($request['user_id']) : null;

        return view('orders.index', compact('orders', 'users', 'request', 'user'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $carrito = session('carrito', null);

        if (!$carrito) return view('orders.create')->with('mensaje', 'error: no existe carrito');

        $pay_forms = OrderPayForm::all();

        $cont = 0;
        $total = 0;
        foreach ($carrito as $item) {
            if ($item["quantity"] > 0) {
                $cont++;
                $total += $item["price"] * $item["quantity"];
            }
        }
        if ($cont > 0)
            return view('orders.create', compact('carrito', 'total', 'pay_forms'));
        else
            return view('orders.create')->with('mensaje', 'error: no existen items en carrito');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $order = new Order();

        $carrito = session('carrito', null);

        if (!$carrito) return back()->with('mensaje', 'Error, no existe carrito');

        $pay_form = OrderPayForm::where('name', $request->pay_form_name)->first();

        $order->user_id = auth()->user()->id;
        $order->note = $request->note;
        $order->pay_form_id = $pay_form->id;
        $order->shipping_date = $request->shipping_date;
        $order->save();
        $id = $order->id;

        foreach ($carrito as $item) {
            if ($item["quantity"] > 0) {
                $price = $item["price"] * $item["quantity"];

                $detalle_pedido = new OrderDetail();
                $detalle_pedido->order_id = $id;
                $detalle_pedido->code = $item["code"];
                $detalle_pedido->detail = $item["detail"];
                $detalle_pedido->quantity = $item["quantity"];
                $detalle_pedido->price = $price;

                $detalle_pedido->save();
            }
        }

        // ? Resto Stock
        $details = Order::findOrFail($id)->details()->get();

        foreach ($details as $detail) {
            $stock = Stock::findOrFail($detail['code']);
            $stock->quantity -= $detail['quantity'];
            $stock->save();
        }

        $request->session()->forget('carrito');

        // Envio email cliente
        Mail::to(auth()->user()->email)->send(new SolicitudPedidoMail());

        // Envio email administradores
        $adminUsers = User::whereAdministrator(1)->get();
        if ($adminUsers && sizeof($adminUsers) > 0) foreach ($adminUsers as $user) {
            Mail::to($user->email)->send(new NewOrder(Order::findOrFail($id), $user));
        }

        return back()->with('mensaje', 'Pedido creado');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Order  $orders
     * @return \Illuminate\Http\Response
     */
    public function show(Order $order, OrderRepository $repository)
    {
        $data = $repository->getOrder(request()->all(), $order);

        $order = $data['order'];
        $detail = $data['detail'];

        return view('orders.show', compact('order', 'detail'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Order  $orders
     * @return \Illuminate\Http\Response
     */
    public function edit(Order $order)
    {
        $order = Order::findOrFail($order->id);
        return view('orders.edit', compact('order'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Order  $orders
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Order $orders)
    {
        //
    }

    public function updateState(Request $request, OrderRepository $repository, $id = null, $status_id = null)
    {
        if (!$id || !$status_id) return;

        if ($status_id != 7 && !Auth::user()->administrator) return;        // status_id 7 == Cancelado

        $order = Order::findOrFail($id);
        $order->status_id = $status_id;
        $order->save();

        $status = OrderStatus::findOrFail($status_id);

        if ($status_id == 2) {
            Mail::to($order->user->email)->send(new OrderConfirmed(Order::findOrFail($id)));
        }
        // ? Sumo Stock si el status es Cancelado (id = 7)
        else if ($status_id == 7) {
            $details = Order::findOrFail($id)->details()->get();

            foreach ($details as $detail) {
                $stock = Stock::findOrFail($detail['code']);
                $stock->quantity += $detail['quantity'];
                $stock->save();
            }
        }

        return back()->with('mensaje', 'Estado actualizado a ' . $status->status);
    }

    public function updateCarrito(Request $request, $id)
    {
        $accion = $request->accion;

        $carrito = session('carrito', null);

        if ($carrito && sizeOf($carrito) > 0) {
            foreach ($carrito as $item => $value) {
                if ($value["id"] == $id) {

                    if ($accion == 'agregado') {
                        $carrito[$item]["quantity"]++;
                    } else if ($carrito[$item]["quantity"] > 0) {
                        $carrito[$item]["quantity"]--;
                    }

                    session(['carrito' => $carrito]);
                    return back()->with('mensaje', 'Articulo ' . $accion . ': ' . $value["detail"]);
                }
            }
            return back()->with('mensaje', 'error: no se encuentra el item');
        } else {
            return back()->with('mensaje', 'error: no existe carrito');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Order  $orders
     * @return \Illuminate\Http\Response
     */
    public function destroy(Order $orders)
    {
        //
    }

    public function getRemito(Order $order, OrderRepository $repository)
    {
        $data = $repository->getOrder(request()->all(), $order);

        logger($data);

        $order = $data['order'];
        $detail = $data['detail'];

        return view('orders.remito', compact('order', 'detail'));
    }
}

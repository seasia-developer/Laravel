<?php

namespace App\Http\Controllers\Backend;

use App\Models\Product;
use App\Models\Tid;
use App\Models\UserOrder;
use App\Services\UploadService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\UserTransaction;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{

    /**
     * @var UploadService
     */
    protected $uploadService;


    /**
     * UploadController constructor.
     */
    public function __construct()
    {
        $this->middleware('backend');
        $this->uploadService = new UploadService();
    }


    /**
     * @param int $pageNumber
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Foundation\Application|\Illuminate\View\View
     */
    public function index( Request $request )
    {
        // $transactions = UserTransaction::where('status', '!=', 'paid')->get()->toArray();
        // dd($transactions);
        $this->validate( $request, [
            'page' => 'integer'
        ] );

        $page = $request->input( 'page', 1 );
        $tids = Tid::where( 'used', 0 )->orderByDesc( 'id' )->paginate( 10, [ '*' ], 'page', $page )->setPath(route('admin.uploads.index'));

        if ( $page > $tids->lastPage() || $page < 1 )
            return redirect()->route( 'admin.uploads.index' );

        return view( 'backend.uploads.index', compact( 'tids' ) );
    }


    /**
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Foundation\Application|\Illuminate\View\View
     */
    public function create( Request $request )
    {
        $products = Product::all();

        return view( 'backend.uploads.create', compact( 'products' ) );
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store( Request $request )
    {
        $this->validate( $request, [
            'product_id' => 'required',
            'tid_file'   => 'required|array',
            'tid_file.*' => 'mimes:pdf'
        ] );

        return $this->uploadService->uploadTidFile( $request );
    }


    /**
     * @param $id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy( $id, Request $request )
    {
        $tid = Tid::find( $id );

        if ( $tid->used == 1 )
            return redirect()->route( 'admin.uploads.index' )->with( 'errorMessage', "Can't be deleted" );

        $this->uploadService->deleteTidFile( $tid->product_id, $tid->tid );
        $tid->delete();

        return redirect()->route( 'admin.uploads.index' )->with( 'successMessage', 'TID files has been deleted' );
    }


    /**
     * @param $id
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function download( $id, Request $request )
    {
        $tid  = Tid::find( $id );
        $path = Storage::disk( 'public' )->path( "tid/$tid->product_id/$tid->tid" );

        return response()->download( $path );
    }

}

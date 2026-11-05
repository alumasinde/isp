<?php

namespace App\Http\Controllers;

use App\Models\CollectionSummary;
use App\Models\CustomersAddress;
use App\Models\User;
use Carbon\Carbon;
use DataTables;
use Illuminate\Http\Request;

class CollectionReportController extends Controller
{
    public function __construct()
    {
        if (! auth()->user()->can('payment-collection-report')) {
            abort(403, 'Unauthorized action.');
        }

        return true;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // if(auth()->user()->can('view collection report')){
        //     abort(403);
        // }
        if ($request->ajax()) {
            $query = CollectionSummary::with([
                'customer',
                'customer.pppUser',
                'customer.billing',
                'customer.customerAddress',
            ]);

            if ($request->filled('fromDate') && $request->filled('toDate')) {
                $from = Carbon::parse($request->fromDate)->startOfDay();
                $to = Carbon::parse($request->toDate)->endOfDay();
                $query->whereBetween('collection_date', [$from, $to]);
            } elseif ($request->filled('fromDate')) {
                $from = Carbon::parse($request->fromDate)->startOfDay();
                $query->where('collection_date', '>=', $from);
            } elseif ($request->filled('toDate')) {
                $to = Carbon::parse($request->toDate)->endOfDay();
                $query->where('collection_date', '<=', $to);
            } else {
                $from = Carbon::now()->startOfMonth()->startOfDay();
                $to = Carbon::now()->endOfMonth()->endOfDay();
                $query->whereBetween('collection_date', [$from, $to]);
            }

            if ($request->filled('collector')) {
                $query->where('collected_by', $request->collector);
            }

            if (! $request->has('order')) {
                $query->orderBy('created_at', 'asc');
            }

            $userMap = [];
            foreach (User::select('name', 'email')->get() as $u) {
                if ($u->email) {
                    $userMap[strtolower(trim($u->email))] = trim($u->name);
                }
            }

            return DataTables::of($query)
                ->addIndexColumn()
                ->addColumn('customer_name', function ($row) {
                    return $row->customer->customer_name ?? 'N/A';
                })
                ->addColumn('mobile', function ($row) {
                    return $row->customer->mobile ?? 'N/A';
                })
                ->addColumn('customers_address', function ($row) {
                    $addresses = $row->customer->customerAddress ?? collect();
                    $formattedAddresses = [];
                    foreach ($addresses as $address) {
                        $parts = array_filter([
                            $address->input_type_text,
                            $address->input_type_dropdown,
                            $address->input_type_textarea,
                        ]);
                        if (!empty($parts)) {
                            $formattedAddresses[] = implode(', ', $parts);
                        }
                    }
                    return !empty($formattedAddresses) ? implode('; ', $formattedAddresses) : 'N/A';
                })
                ->addColumn('ppp_secret', function ($row) {
                    return $row->customer->pppUser->username ?? 'N/A';
                })
                ->addColumn('monthly_rent', function ($row) {
                    return (float) ($row->customer->billing->monthly_rent ?? 0);
                })
                ->addColumn('discount', function ($row) {
                    return (float) ($row->customer->billing->discount ?? 0);
                })
                ->addColumn('due_amount', function ($row) {
                    return (float) ($row->customer->billing->due_amount ?? 0);
                })
                ->addColumn('payment_method', function ($row) {
                    return ucfirst($row->payment_method ?? ($row->payment_type ?? 'Cash'));
                })
                ->addColumn('transaction_id', function ($row) {
                    return $row->transaction_id ?? '';
                })
                ->addColumn('invoice_no', function ($row) {
                    return $row->id ? '#' . siteUrlSettings('site_invoice_prefix') . $row->id : 'N/A';
                })
                ->addColumn('collected_by', function ($row) use ($userMap) {
                    $collectedBy = trim($row->collected_by ?? '');
                    if (empty($collectedBy)) {
                        return 'N/A';
                    }

                    $lower = strtolower($collectedBy);
                    $name = $userMap[$lower] ?? null;

                    if ($name && strtolower($name) !== $lower) {
                        return $name . ' (' . $collectedBy . ')';
                    }

                    return $collectedBy;
                })
                ->rawColumns(['customers_address', 'ppp_secret'])
                ->make(true);
        }
        $collectors = User::select('name', 'email')->get();

        return view('reports.collections.index', compact('collectors'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

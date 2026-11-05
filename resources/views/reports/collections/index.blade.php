<x-app-layout>
    <x-slot name="header">
        {{ __('Collections') }}
    </x-slot>
    <div class="row">
        <div class="col">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-3">
                    <form class="row g-3 align-items-end" id="collection-report-form" onsubmit="return false;">
                        <div class="col-md-3 col-sm-6">
                            <label for="from-date" class="form-label small fw-semibold text-muted mb-1">{{ __('From Date') }}</label>
                            <input type="date" name="from-date" class="form-control" id="from-date" value="{{ now()->startOfMonth()->format('Y-m-d') }}">
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label for="to-date" class="form-label small fw-semibold text-muted mb-1">{{ __('To Date') }}</label>
                            <input type="date" name="to-date" class="form-control" id="to-date" value="{{ now()->format('Y-m-d') }}">
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label for="collector" class="form-label small fw-semibold text-muted mb-1">{{ __('Collector') }}</label>
                            <select name="collector" id="collector" class="form-select">
                                <option value="">{{ __('All Collectors') }}</option>
                                @foreach ($collectors as $collector)
                                    <option value="{{ $collector->email }}">{{ $collector->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6 d-flex gap-2">
                            <button type="submit" id="report-submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-1"></i> {{ __('Filter') }}
                            </button>
                            <button type="button" id="report-reset" class="btn btn-outline-secondary" title="{{ __('Reset Filter') }}">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle w-100" id="collection-report-table">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Invoice No') }}</th>
                                    <th>{{ __('Customer ID') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Customer Name') }}</th>
                                    <th>{{ __('Mobile') }}</th>
                                    <th>{{ __('IP/User') }}</th>
                                    <th>{{ __('Address') }}</th>
                                    <th class="text-end">{{ __('Bill') }}</th>
                                    <th class="text-end">{{ __('Discount') }}</th>
                                    <th class="text-end">{{ __('Collection') }}</th>
                                    <th class="text-end">{{ __('Due') }}</th>
                                    <th>{{ __('Method') }}</th>
                                    <th>{{ __('Trx ID') }}</th>
                                    <th>{{ __('Collected By') }}</th>
                                </tr>
                            </thead>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <th colspan="7" class="text-end">{{ __('Page Total:') }}</th>
                                    <th class="text-end" id="footer-bill">0.00</th>
                                    <th class="text-end text-warning" id="footer-discount">0.00</th>
                                    <th class="text-end text-success" id="footer-collected">0.00</th>
                                    <th class="text-end text-danger" id="footer-due">0.00</th>
                                    <th colspan="3"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            (function () {
                var reportTable = null;

                function loadDataTable() {
                    var $table = $('#collection-report-table');
                    if ($table.length === 0) return;

                    if ($.fn.DataTable.isDataTable('#collection-report-table')) {
                        reportTable = $('#collection-report-table').DataTable();
                        reportTable.ajax.reload();
                        return;
                    }

                    reportTable = $table.DataTable({
                        processing: true,
                        autoWidth: false,
                        responsive: true,
                        lengthMenu: [
                            [10, 25, 50, 100, -1],
                            [10, 25, 50, 100, 'All']
                        ],
                        dom: '<"d-flex flex-wrap justify-content-between align-items-center mb-3"Bf>rt<"d-flex flex-wrap justify-content-between align-items-center mt-3"lip>',
                        buttons: [
                            'copy', 'csv', 'excel', 'pdf', 'print'
                        ],
                        order: [],
                        ajax: {
                            url: "{{ route('collection-report.index') }}",
                            data: function (d) {
                                d.fromDate = $('#from-date').val();
                                d.toDate = $('#to-date').val();
                                d.collector = $('#collector').val();
                            }
                        },
                        columns: [
                            { data: 'invoice_no', name: 'invoice_no', defaultContent: 'N/A' },
                            { data: 'customer_collection_unique_id', name: 'customer_collection_unique_id' },
                            { 
                                data: 'collection_date', 
                                name: 'collection_date',
                                render: function (data) {
                                    if (!data) return 'N/A';
                                    var cleanDate = data.replace(' ', 'T');
                                    var date = new Date(cleanDate);
                                    if (isNaN(date.getTime())) return data;
                                    return date.toLocaleDateString('en-GB', { 
                                        day: '2-digit', 
                                        month: 'short', 
                                        year: 'numeric'
                                    });
                                }
                            },
                            { data: 'customer_name', name: 'customer.customer_name', defaultContent: 'N/A' },
                            { data: 'mobile', name: 'customer.mobile', defaultContent: 'N/A' },
                            { data: 'ppp_secret', name: 'ppp_secret', defaultContent: 'N/A' },
                            { data: 'customers_address', name: 'customers_address', orderable: false, searchable: false, defaultContent: 'N/A' },
                            { 
                                data: 'monthly_rent', 
                                name: 'monthly_rent',
                                className: 'text-end',
                                render: function (data) {
                                    return parseFloat(data || 0).toFixed(2);
                                }
                            },
                            { 
                                data: 'discount', 
                                name: 'discount',
                                className: 'text-end',
                                render: function (data) {
                                    return parseFloat(data || 0).toFixed(2);
                                }
                            },
                            { 
                                data: 'collection_amount', 
                                name: 'collection_amount',
                                className: 'text-end fw-bold',
                                render: function (data) {
                                    return parseFloat(data || 0).toFixed(2);
                                }
                            },
                            { 
                                data: 'due_amount', 
                                name: 'due_amount',
                                className: 'text-end',
                                render: function (data) {
                                    return parseFloat(data || 0).toFixed(2);
                                }
                            },
                            { data: 'payment_method', name: 'payment_method', defaultContent: 'Cash' },
                            { 
                                data: 'transaction_id', 
                                name: 'transaction_id', 
                                defaultContent: 'N/A',
                                render: function (data) {
                                    return data ? data : 'N/A';
                                }
                            },
                            { data: 'collected_by', name: 'collected_by', defaultContent: 'N/A' }
                        ],
                        footerCallback: function (row, data, start, end, display) {
                            var api = this.api();
                            var numVal = function (i) {
                                if (typeof i === 'string') {
                                    return parseFloat(i.replace(/<[^>]*>?/gm, '').replace(/,/g, '')) || 0;
                                }
                                return typeof i === 'number' ? i : 0;
                            };

                            var totalBill = api.column(7, { page: 'current' }).data().reduce(function (a, b) { return numVal(a) + numVal(b); }, 0);
                            var totalDiscount = api.column(8, { page: 'current' }).data().reduce(function (a, b) { return numVal(a) + numVal(b); }, 0);
                            var totalCollected = api.column(9, { page: 'current' }).data().reduce(function (a, b) { return numVal(a) + numVal(b); }, 0);
                            var totalDue = api.column(10, { page: 'current' }).data().reduce(function (a, b) { return numVal(a) + numVal(b); }, 0);

                            $('#footer-bill').text(totalBill.toFixed(2));
                            $('#footer-discount').text(totalDiscount.toFixed(2));
                            $('#footer-collected').text(totalCollected.toFixed(2));
                            $('#footer-due').text(totalDue.toFixed(2));
                        },
                        language: {
                            emptyTable: "{{ __('No collection records found for the selected date range') }}",
                        }
                    });
                }

                function initReport() {
                    $('#collection-report-form').off('submit').on('submit', function (e) {
                        e.preventDefault();
                        if (reportTable) {
                            reportTable.ajax.reload();
                        } else {
                            loadDataTable();
                        }
                    });

                    $('#report-reset').off('click').on('click', function () {
                        $('#from-date').val("{{ now()->startOfMonth()->format('Y-m-d') }}");
                        $('#to-date').val("{{ now()->format('Y-m-d') }}");
                        $('#collector').val('');
                        if (reportTable) {
                            reportTable.ajax.reload();
                        }
                    });

                    loadDataTable();
                }

                document.addEventListener('DOMContentLoaded', initReport);
                document.addEventListener('livewire:navigated', initReport);
            })();
        </script>
    @endpush
</x-app-layout>

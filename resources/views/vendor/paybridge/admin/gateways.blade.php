<x-app-layout>
    <div class="container-fluid py-3">

        {{-- Page Header --}}
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 p-md-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="avatar avatar-xl bg-primary-subtle text-primary rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="bi bi-credit-card-2-front fs-3"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h4 class="mb-0 fw-bold">{{ __('Payment Gateway Settings') }}</h4>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 text-xs">PayBridge Unified</span>
                        </div>
                        <p class="text-body-secondary mb-0 small">
                            {{ __('Manage multi-gateway payment configurations, test modes, and encrypted API credentials.') }}
                        </p>
                    </div>
                </div>

                {{-- Stats Summary Pills --}}
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill">
                        <i class="bi bi-check-circle-fill me-1"></i> {{ $gateways->where('is_active', true)->count() }} {{ __('Active') }}
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-2 rounded-pill">
                        <i class="bi bi-layers-fill me-1"></i> {{ $gateways->count() }} {{ __('Total Gateways') }}
                    </span>
                    @php
                        $defaultGw = $gateways->firstWhere('is_default', true);
                    @endphp
                    @if($defaultGw)
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2 rounded-pill">
                            <i class="bi bi-star-fill me-1"></i> {{ __('Default:') }} {{ $defaultGw->name }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Success / Error Alerts --}}
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 d-flex align-items-center" role="alert">
                <i class="bi bi-check-circle-fill fs-5 me-2"></i>
                <div class="flex-grow-1">{{ session('success') }}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if (isset($errors) && $errors->any())
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
                <div class="d-flex align-items-center mb-1">
                    <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                    <strong>{{ __('Please check the form for errors:') }}</strong>
                </div>
                <ul class="mb-0 ps-4 small">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Category Filters --}}
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-2">
                <ul class="nav nav-pills flex-nowrap overflow-auto gap-1 pb-1" id="gatewayCategoryTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill px-3 py-1.5 text-nowrap" type="button" onclick="filterCategory('all', this)">
                            <i class="bi bi-grid-fill me-1"></i> {{ __('All Gateways') }} ({{ $gateways->count() }})
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1.5 text-nowrap" type="button" onclick="filterCategory('MFS', this)">
                            <i class="bi bi-phone-fill me-1"></i> {{ __('MFS / Mobile Banking') }}
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1.5 text-nowrap" type="button" onclick="filterCategory('Aggregators', this)">
                            <i class="bi bi-shield-check me-1"></i> {{ __('Aggregators') }}
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1.5 text-nowrap" type="button" onclick="filterCategory('International Cards', this)">
                            <i class="bi bi-credit-card-fill me-1"></i> {{ __('International Cards') }}
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1.5 text-nowrap" type="button" onclick="filterCategory('Crypto', this)">
                            <i class="bi bi-currency-bitcoin me-1"></i> {{ __('Crypto') }}
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-3 py-1.5 text-nowrap" type="button" onclick="filterCategory('Banks & QR', this)">
                            <i class="bi bi-qr-code-scan me-1"></i> {{ __('Banks & QR') }}
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        {{-- Gateways Grid --}}
        <div class="row g-3" id="gatewaysContainer">
            @foreach($gateways as $gateway)
                @php
                    $definition = $definitions[$gateway->code] ?? [];
                    $category = $definition['category'] ?? $gateway->category;
                    
                    // Gateway branding accents
                    $brandColor = 'primary';
                    $brandIcon = 'bi-credit-card-2-front';
                    if (str_contains($gateway->code, 'bkash')) {
                        $brandColor = 'danger';
                        $brandIcon = 'bi-wallet2';
                    } elseif (str_contains($gateway->code, 'nagad')) {
                        $brandColor = 'warning';
                        $brandIcon = 'bi-cash-stack';
                    } elseif (str_contains($gateway->code, 'rocket')) {
                        $brandColor = 'purple';
                        $brandIcon = 'bi-send';
                    } elseif (str_contains($gateway->code, 'upay')) {
                        $brandColor = 'info';
                        $brandIcon = 'bi-phone';
                    } elseif (str_contains($gateway->code, 'sslcommerz')) {
                        $brandColor = 'primary';
                        $brandIcon = 'bi-shield-check';
                    } elseif (str_contains($gateway->code, 'stripe')) {
                        $brandColor = 'indigo';
                        $brandIcon = 'bi-stripe';
                    } elseif (str_contains($gateway->code, 'paypal')) {
                        $brandColor = 'primary';
                        $brandIcon = 'bi-paypal';
                    } elseif (str_contains($gateway->code, 'binance') || str_contains($gateway->code, 'crypto') || str_contains($gateway->code, 'nowpayments')) {
                        $brandColor = 'warning';
                        $brandIcon = 'bi-currency-bitcoin';
                    } elseif (str_contains($gateway->code, 'qr')) {
                        $brandColor = 'success';
                        $brandIcon = 'bi-qr-code-scan';
                    }
                @endphp

                <div class="col-12 col-md-6 col-xl-4 gateway-item" data-category="{{ $category }}">
                    <div class="card h-100 border shadow-sm transition-all position-relative {{ $gateway->is_active ? 'border-primary-subtle' : 'border-light-subtle opacity-90' }}">
                        
                        {{-- Card Header --}}
                        <div class="card-header bg-transparent border-bottom-0 pb-0 pt-3 px-3 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar avatar-m bg-{{ $brandColor }}-subtle text-{{ $brandColor }} rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                    <i class="bi {{ $brandIcon }} fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold text-truncate" style="max-width: 170px;" title="{{ $gateway->name }}">
                                        {{ $gateway->name }}
                                    </h6>
                                    <span class="badge bg-secondary-subtle text-secondary py-0.5 px-2 text-[10px] rounded-pill">
                                        {{ $category }}
                                    </span>
                                </div>
                            </div>

                            {{-- Active Toggle Switch --}}
                            <form action="{{ route('paybridge.admin.toggle', $gateway->code) }}" method="POST" class="mb-0">
                                @csrf
                                <div class="form-check form-switch form-switch-md mb-0">
                                    <input class="form-check-input cursor-pointer" type="checkbox" role="switch"
                                        title="{{ $gateway->is_active ? __('Click to Disable') : __('Click to Enable') }}"
                                        onchange="this.form.submit()" {{ $gateway->is_active ? 'checked' : '' }}>
                                </div>
                            </form>
                        </div>

                        {{-- Card Body --}}
                        <div class="card-body p-3">
                            <p class="text-body-secondary small mb-3 text-truncate-2" style="min-height: 38px;">
                                {{ $definition['description'] ?? __('Configure credentials to accept payments.') }}
                            </p>

                            {{-- Status Badges --}}
                            <div class="d-flex flex-wrap align-items-center gap-1.5 mb-2">
                                @if($gateway->is_active)
                                    <span class="badge bg-success-subtle text-success py-1 px-2 rounded-pill font-semibold">
                                        <i class="bi bi-circle-fill me-1 text-[8px]"></i>{{ __('Active') }}
                                    </span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary py-1 px-2 rounded-pill">
                                        <i class="bi bi-dash-circle me-1"></i>{{ __('Inactive') }}
                                    </span>
                                @endif

                                @if($gateway->is_sandbox)
                                    <span class="badge bg-warning-subtle text-warning-emphasis py-1 px-2 rounded-pill">
                                        <i class="bi bi-wrench-adjustable me-1"></i>{{ __('Sandbox (Test)') }}
                                    </span>
                                @else
                                    <span class="badge bg-info-subtle text-info-emphasis py-1 px-2 rounded-pill">
                                        <i class="bi bi-broadcast me-1"></i>{{ __('Live') }}
                                    </span>
                                @endif

                                @if($gateway->is_default)
                                    <span class="badge bg-primary text-white py-1 px-2 rounded-pill">
                                        <i class="bi bi-star-fill me-1"></i>{{ __('Default') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Card Footer --}}
                        <div class="card-footer bg-transparent border-top pt-2 pb-3 px-3 d-flex align-items-center justify-content-between gap-2">
                            @if(!$gateway->is_default)
                                <form action="{{ route('paybridge.admin.default', $gateway->code) }}" method="POST" class="mb-0">
                                    @csrf
                                    <button type="submit" class="btn btn-link btn-sm text-secondary text-decoration-none p-0 fw-semibold">
                                        <i class="bi bi-star me-1"></i>{{ __('Make Default') }}
                                    </button>
                                </form>
                            @else
                                <span class="small text-body-secondary fw-semibold">
                                    <i class="bi bi-check-all text-primary me-1"></i>{{ __('Primary Default') }}
                                </span>
                            @endif

                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm"
                                data-bs-toggle="modal" data-bs-target="#modal-{{ $gateway->code }}">
                                <i class="bi bi-gear-fill me-1"></i>{{ __('Configure') }}
                            </button>
                        </div>

                    </div>
                </div>

                {{-- Configuration Modal for this Gateway --}}
                <div class="modal fade" id="modal-{{ $gateway->code }}" tabindex="-1" aria-labelledby="modalLabel-{{ $gateway->code }}" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content border-0 shadow-lg">
                            <form action="{{ route('paybridge.admin.update', $gateway->code) }}" method="POST">
                                @csrf

                                <div class="modal-header border-bottom">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar avatar-m bg-{{ $brandColor }}-subtle text-{{ $brandColor }} rounded-2 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                            <i class="bi {{ $brandIcon }}"></i>
                                        </div>
                                        <h5 class="modal-title fw-bold" id="modalLabel-{{ $gateway->code }}">
                                            {{ __('Configure') }} {{ $gateway->name }}
                                        </h5>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body p-4">
                                    {{-- Switch Settings: Active, Sandbox, Default --}}
                                    <div class="row g-3 mb-4 p-3 bg-body-tertiary rounded-3 border">
                                        <div class="col-md-4">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                    id="active-{{ $gateway->code }}" {{ $gateway->is_active ? 'checked' : '' }}>
                                                <label class="form-check-label fw-semibold" for="active-{{ $gateway->code }}">
                                                    {{ __('Enable Gateway') }}
                                                </label>
                                            </div>
                                            <span class="text-muted small d-block">{{ __('Allow customers to select this gateway') }}</span>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="is_sandbox" value="1"
                                                    id="sandbox-{{ $gateway->code }}" {{ $gateway->is_sandbox ? 'checked' : '' }}>
                                                <label class="form-check-label fw-semibold" for="sandbox-{{ $gateway->code }}">
                                                    {{ __('Sandbox / Test Mode') }}
                                                </label>
                                            </div>
                                            <span class="text-muted small d-block">{{ __('Use sandbox API simulator') }}</span>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="is_default" value="1"
                                                    id="default-{{ $gateway->code }}" {{ $gateway->is_default ? 'checked' : '' }}>
                                                <label class="form-check-label fw-semibold" for="default-{{ $gateway->code }}">
                                                    {{ __('Set As Default') }}
                                                </label>
                                            </div>
                                            <span class="text-muted small d-block">{{ __('Preselected on customer checkout') }}</span>
                                        </div>
                                    </div>

                                    {{-- Gateway Credentials Inputs --}}
                                    <h6 class="fw-bold mb-3 d-flex align-items-center gap-1.5 text-primary">
                                        <i class="bi bi-shield-lock-fill"></i> {{ __('API Credentials & Settings') }}
                                    </h6>

                                    @php
                                        $fields = $definition['fields'] ?? [];
                                        $savedCredentials = $gateway->credentials ?? [];
                                    @endphp

                                    <div class="row g-3">
                                        @forelse($fields as $fieldName => $fieldMeta)
                                            @php
                                                $fieldPlaceholder = !empty($fieldMeta['placeholder']) 
                                                    ? $fieldMeta['placeholder'] 
                                                    : match($fieldName) {
                                                        'app_key' => 'e.g. 5c98a3b2b... or App Key',
                                                        'app_secret' => 'Enter App Secret Key',
                                                        'username' => 'e.g. merchant_username',
                                                        'password' => 'Enter Password',
                                                        'merchant_id' => 'e.g. 683002007104225',
                                                        'merchant_number' => 'e.g. 017xxxxxxxx',
                                                        'public_key' => "-----BEGIN PUBLIC KEY-----\n...",
                                                        'private_key' => "-----BEGIN RSA PRIVATE KEY-----\n...",
                                                        'merchant_key' => 'Enter Merchant Key / Secret',
                                                        'merchant_password' => 'Enter Merchant Password',
                                                        'store_id' => 'e.g. testbox or Store ID',
                                                        'store_password' => 'Enter Store Password',
                                                        'signature_key' => 'Enter Signature Key',
                                                        'secret_key' => 'Enter Secret Key',
                                                        'merchant_name' => 'e.g. ' . config('app.name', 'My Business'),
                                                        'merchant_prefix' => 'e.g. TXN',
                                                        'client_id' => 'e.g. PayPal Client ID',
                                                        'secret' => 'Enter Secret',
                                                        'webhook_secret' => 'e.g. whsec_...',
                                                        'api_key' => 'e.g. API Key',
                                                        'ipn_secret' => 'Enter IPN Secret',
                                                        'terminal_id' => 'e.g. TID00001',
                                                        'acquirer_bin' => 'e.g. 000001',
                                                        'mcc' => 'e.g. 5411',
                                                        'merchant_city' => 'e.g. Dhaka',
                                                        default => ($fieldMeta['default'] ?? 'Enter ' . ($fieldMeta['label'] ?? ucfirst(str_replace('_', ' ', $fieldName)))),
                                                    };
                                            @endphp
                                            <div class="{{ ($fieldMeta['type'] ?? 'text') === 'textarea' ? 'col-12' : 'col-md-6' }}">
                                                <label class="form-label fw-semibold small mb-1" for="input-{{ $gateway->code }}-{{ $fieldName }}">
                                                    {{ $fieldMeta['label'] }}
                                                    @if(($fieldMeta['type'] ?? 'text') === 'password')
                                                        <span class="text-muted font-normal small">({{ __('Encrypted') }})</span>
                                                    @endif
                                                </label>

                                                @if(($fieldMeta['type'] ?? 'text') === 'textarea')
                                                    <textarea
                                                        name="credentials[{{ $fieldName }}]"
                                                        id="input-{{ $gateway->code }}-{{ $fieldName }}"
                                                        class="form-control rounded-3 font-monospace small"
                                                        rows="3"
                                                        placeholder="{{ $fieldPlaceholder }}"
                                                    >{{ $savedCredentials[$fieldName] ?? '' }}</textarea>
                                                @else
                                                    <div class="input-group">
                                                        <input
                                                            type="{{ $fieldMeta['type'] ?? 'text' }}"
                                                            name="credentials[{{ $fieldName }}]"
                                                            id="input-{{ $gateway->code }}-{{ $fieldName }}"
                                                            value="{{ $savedCredentials[$fieldName] ?? ($fieldMeta['default'] ?? '') }}"
                                                            class="form-control rounded-start-3"
                                                            placeholder="{{ (($fieldMeta['type'] ?? 'text') === 'password' && !empty($savedCredentials[$fieldName])) ? '•••••••••••••••• (Leave blank to keep current)' : $fieldPlaceholder }}"
                                                        >
                                                        @if(($fieldMeta['type'] ?? 'text') === 'password')
                                                            <button class="btn btn-outline-secondary rounded-end-3" type="button"
                                                                onclick="togglePasswordVisibility('input-{{ $gateway->code }}-{{ $fieldName }}', this)">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @empty
                                            <div class="col-12 text-muted small py-2">
                                                {{ __('No custom credential fields required for this driver.') }}
                                            </div>
                                        @endforelse
                                    </div>
                                </div>

                                <div class="modal-footer border-top bg-body-tertiary">
                                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">
                                        {{ __('Cancel') }}
                                    </button>
                                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">
                                        <i class="bi bi-check2 me-1"></i>{{ __('Save Changes') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

    </div>

    @push('scripts')
    <script>
        function filterCategory(category, btn) {
            document.querySelectorAll('#gatewayCategoryTabs .nav-link').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');

            const items = document.querySelectorAll('.gateway-item');
            items.forEach(item => {
                const itemCat = item.getAttribute('data-category') || '';
                if (category === 'all' || itemCat.toLowerCase() === category.toLowerCase() || itemCat.toLowerCase().includes(category.toLowerCase())) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const icon = btn ? btn.querySelector('i') : null;

            if (input.type === 'password') {
                input.type = 'text';
                if (icon) {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                }
            } else {
                input.type = 'password';
                if (icon) {
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        }
    </script>
    @endpush
</x-app-layout>

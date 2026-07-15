<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Order Meja {{ $tableCode }} | Warung Babeh</title>
    <style>
        :root {
            --green: #0D6B3A;
            --deep: #004B36;
            --gold: #E3B51C;
            --yellow: #FFD23C;
            --cream: #FFF6D8;
            --white: #FFFFFF;
            --line: #FFE8A3;
            --soft: #EEF7F0;
            --soft-2: #F7F7F1;
            --text: #004B36;
            --muted: #6E776C;
            --danger: #A94438;
            --danger-bg: #FFF4F1;
            --shadow: 0 18px 36px rgba(0, 75, 54, 0.10);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #FFFDF5 0%, var(--cream) 100%);
            color: var(--text);
        }

        button,
        input,
        textarea {
            font: inherit;
        }

        .page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 18px 16px 120px;
        }

        .header-card,
        .panel,
        .menu-card {
            border-radius: 24px;
        }

        .header-card {
            padding: 22px 20px;
            background: linear-gradient(135deg, var(--green), var(--deep));
            color: var(--white);
            box-shadow: 0 18px 42px rgba(0, 75, 54, 0.18);
        }

        .header-card h1 {
            margin: 0;
            font-size: clamp(28px, 4vw, 36px);
            line-height: 1.08;
            font-weight: 800;
        }

        .header-card p {
            margin: 8px 0 0;
            color: rgba(255, 255, 255, 0.92);
            line-height: 1.5;
            max-width: 780px;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 14px;
            font-weight: 800;
        }

        .panel {
            margin-top: 18px;
            padding: 18px;
            background: var(--white);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }

        .panel h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .panel p {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .customer-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .field {
            grid-column: span 12;
        }

        .field-sm {
            grid-column: span 4;
        }

        .field-md {
            grid-column: span 6;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 800;
            color: var(--muted);
        }

        .input,
        .textarea {
            width: 100%;
            border: 1px solid #F0DB96;
            border-radius: 16px;
            background: var(--white);
            color: var(--text);
            padding: 14px 16px;
            outline: none;
            transition: border-color 160ms ease, box-shadow 160ms ease;
        }

        .textarea {
            min-height: 96px;
            resize: vertical;
        }

        .input:focus,
        .textarea:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 4px rgba(13, 107, 58, 0.08);
        }

        .control-panel {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding-left: 46px;
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 18px;
        }

        .chips-row {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: none;
        }

        .chips-row::-webkit-scrollbar {
            display: none;
        }

        .category-chip {
            flex: 0 0 auto;
            border: 1px solid var(--line);
            background: var(--white);
            color: var(--deep);
            padding: 12px 18px;
            border-radius: 999px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 160ms ease, box-shadow 160ms ease, background 160ms ease, color 160ms ease;
        }

        .category-chip.active {
            background: var(--green);
            color: var(--white);
            border-color: var(--green);
            box-shadow: 0 10px 20px rgba(13, 107, 58, 0.18);
        }

        .stat-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .stat-pill {
            min-width: 140px;
            padding: 12px 14px;
            border-radius: 16px;
            background: var(--soft);
        }

        .stat-pill.alert {
            background: var(--danger-bg);
            border: 1px solid #F5CEC7;
        }

        .stat-pill strong {
            display: block;
            font-size: 13px;
            color: rgba(0, 75, 54, 0.78);
        }

        .stat-pill span {
            display: block;
            margin-top: 4px;
            font-size: 18px;
            font-weight: 800;
        }

        .menu-summary {
            margin: 18px 0 12px;
            font-size: 16px;
            font-weight: 800;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .menu-card {
            overflow: hidden;
            border: 1px solid var(--line);
            background: var(--white);
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
        }

        .menu-card.disabled {
            background: #FFFFFA;
            border-color: #FFD0C8;
        }

        .menu-cover {
            position: relative;
            height: 172px;
            background: linear-gradient(135deg, #EDF6F0, #FFF6D8);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .menu-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .menu-cover-fallback {
            font-size: 44px;
            color: rgba(0, 75, 54, 0.82);
            font-weight: 800;
        }

        .menu-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .menu-title {
            margin: 0;
            font-size: 18px;
            line-height: 1.28;
            font-weight: 800;
            color: var(--deep);
        }

        .menu-chip-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .info-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--soft);
            color: var(--green);
            font-size: 13px;
            font-weight: 800;
        }

        .price-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .price-pill {
            padding: 6px 10px;
            border-radius: 999px;
            background: #FFF7DA;
            color: var(--gold);
            font-size: 15px;
            font-weight: 900;
        }

        .selected-pill {
            margin-left: auto;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--soft-2);
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .selected-pill.active {
            background: var(--soft);
            color: var(--deep);
        }

        .menu-description {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
            min-height: 44px;
        }

        .menu-unavailable {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 12px;
            border-radius: 999px;
            background: var(--danger-bg);
            color: var(--danger);
            font-size: 13px;
            font-weight: 900;
            width: fit-content;
        }

        .variant-stack {
            display: grid;
            gap: 10px;
        }

        .variant-card {
            border: 1px solid #F4E2AA;
            border-radius: 18px;
            background: #FFFDF7;
            padding: 12px;
        }

        .variant-card.unavailable {
            border-color: #F5CEC7;
            background: #FFFFFB;
        }

        .variant-top {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 10px;
        }

        .variant-top strong {
            font-size: 15px;
            line-height: 1.3;
        }

        .variant-top small {
            display: block;
            margin-top: 4px;
            color: var(--muted);
        }

        .variant-badge {
            color: var(--danger);
            font-size: 13px;
            font-weight: 900;
            white-space: nowrap;
        }

        .qty-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 14px;
        }

        .qty-btn {
            width: 48px;
            height: 48px;
            border: none;
            border-radius: 16px;
            background: #E5F4EA;
            color: var(--green);
            font-size: 26px;
            line-height: 1;
            cursor: pointer;
        }

        .qty-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .qty-input {
            width: 84px;
            min-width: 84px;
            text-align: center;
            border: none;
            border-radius: 16px;
            padding: 12px 10px;
            background: #FFFCF1;
            color: var(--deep);
            font-size: 22px;
            font-weight: 800;
            outline: none;
        }

        .note-field {
            margin-top: 12px;
        }

        .note-field input {
            width: 100%;
            border: 1px solid #F0DB96;
            border-radius: 14px;
            padding: 11px 13px;
            outline: none;
            color: var(--deep);
            background: var(--white);
        }

        .note-field input:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 4px rgba(13, 107, 58, 0.08);
        }

        .cart-bar {
            position: fixed;
            left: 16px;
            right: 16px;
            bottom: 14px;
            z-index: 30;
            display: flex;
            justify-content: center;
            pointer-events: none;
        }

        .cart-inner {
            width: min(100%, 420px);
            background: transparent;
            pointer-events: auto;
        }

        .submit-button {
            width: 100%;
            border: none;
            border-radius: 20px;
            background: var(--green);
            color: var(--white);
            padding: 16px 18px;
            font-size: 18px;
            font-weight: 900;
            box-shadow: 0 14px 28px rgba(0, 75, 54, 0.24);
            cursor: pointer;
        }

        .submit-button:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            box-shadow: none;
        }

        .cart-note {
            margin-top: 8px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
        }

        .feedback {
            display: none;
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 18px;
            line-height: 1.5;
            font-weight: 700;
        }

        .feedback.show {
            display: block;
        }

        .feedback.error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #F5CEC7;
        }

        .feedback.success {
            background: #EDF8F0;
            color: var(--green);
            border: 1px solid #CDE7D4;
        }

        .empty-state {
            display: none;
            padding: 28px 18px;
            text-align: center;
            border-radius: 24px;
            border: 1px solid var(--line);
            background: var(--white);
            color: var(--muted);
            box-shadow: var(--shadow);
        }

        .empty-state.show {
            display: block;
        }

        @media (max-width: 1080px) {
            .menu-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .page {
                padding-bottom: 132px;
            }

            .customer-grid {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }

            .field,
            .field-sm,
            .field-md {
                grid-column: span 1;
            }

            .panel {
                padding: 16px;
            }

            .menu-grid {
                grid-template-columns: 1fr;
            }

            .menu-cover {
                height: 164px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="header-card">
            <div class="hero-badge">Meja {{ $tableCode }}</div>
            <h1>Tambah Order</h1>
            <p>Pilih menu yang ingin Anda pesan dari meja ini, lalu kirim pesanan ke tim restoran.</p>
        </section>

        <section class="panel">
            <h2>Data Pemesan</h2>
            <p>Isi nama pelanggan dan jumlah tamu agar pesanan dapat diproses dengan tepat.</p>
            <div class="customer-grid">
                <div class="field field-md">
                    <label for="customerName">Nama pelanggan</label>
                    <input id="customerName" class="input" type="text" placeholder="Contoh: Bayu">
                </div>
                <div class="field field-sm">
                    <label for="guestCount">Jumlah tamu</label>
                    <input id="guestCount" class="input" type="number" min="1" value="1">
                </div>
                <div class="field field-sm">
                    <label for="customerPhone">Nomor telepon</label>
                    <input id="customerPhone" class="input" type="text" placeholder="Opsional">
                </div>
                <div class="field">
                    <label for="orderNotes">Catatan umum</label>
                    <textarea id="orderNotes" class="textarea" placeholder="Contoh: mohon alat makan tambahan"></textarea>
                </div>
            </div>
            <div id="feedbackError" class="feedback error"></div>
            <div id="feedbackSuccess" class="feedback success"></div>
        </section>

        <section class="panel">
            <div class="control-panel">
                <div>
                    <h2>Cari &amp; filter menu</h2>
                    <p>Pilih kategori dan cari menu seperti pada halaman tambah order di aplikasi kasir.</p>
                </div>
                <div class="search-box">
                    <span class="search-icon">⌕</span>
                    <input id="searchInput" class="input" type="search" placeholder="Cari menu, SKU, atau kategori">
                </div>
                <div id="categoryChips" class="chips-row"></div>
                <div class="stat-row">
                    <div class="stat-pill">
                        <strong>Siap dipesan</strong>
                        <span id="readyCount">0 menu</span>
                    </div>
                    <div id="unavailableStat" class="stat-pill alert" style="display:none;">
                        <strong>Ditutup / habis</strong>
                        <span id="unavailableCount">0 menu</span>
                    </div>
                </div>
            </div>
        </section>

        <div id="menuSummary" class="menu-summary">0 menu siap dipesan</div>
        <div id="menuEmpty" class="empty-state">Tidak ada menu yang cocok dengan filter atau pencarian.</div>
        <div id="menuContainer" class="menu-grid"></div>
    </div>

    <div class="cart-bar">
        <div class="cart-inner">
            <button id="checkoutButton" class="submit-button" type="button" disabled>Kirim 0 item | Rp 0</button>
            <div id="cartNote" class="cart-note">Pilih menu terlebih dahulu.</div>
        </div>
    </div>

    <script>
        const tableCode = @json($tableCode);
        const apiBase = `${window.location.origin}/api/v1`;
        const state = {
            table: null,
            categories: [],
            selectedCategory: 'Semua',
            query: '',
            selected: new Map(),
        };

        const menuContainer = document.getElementById('menuContainer');
        const menuEmpty = document.getElementById('menuEmpty');
        const categoryChips = document.getElementById('categoryChips');
        const checkoutButton = document.getElementById('checkoutButton');
        const cartNote = document.getElementById('cartNote');
        const feedbackError = document.getElementById('feedbackError');
        const feedbackSuccess = document.getElementById('feedbackSuccess');
        const searchInput = document.getElementById('searchInput');
        const readyCount = document.getElementById('readyCount');
        const unavailableStat = document.getElementById('unavailableStat');
        const unavailableCount = document.getElementById('unavailableCount');
        const menuSummary = document.getElementById('menuSummary');

        function currency(value) {
            return new Intl.NumberFormat('id-ID').format(Number(value || 0));
        }

        function showError(message) {
            feedbackError.textContent = message;
            feedbackError.classList.add('show');
            feedbackSuccess.classList.remove('show');
        }

        function showSuccess(message) {
            feedbackSuccess.textContent = message;
            feedbackSuccess.classList.add('show');
            feedbackError.classList.remove('show');
        }

        function clearFeedback() {
            feedbackError.classList.remove('show');
            feedbackSuccess.classList.remove('show');
        }

        function getSelectionKey(menuId, optionId = null) {
            return optionId === null ? `menu:${menuId}` : `menu:${menuId}:option:${optionId}`;
        }

        function cloneSelection(menu, option = null) {
            const key = getSelectionKey(menu.id, option?.id ?? null);
            return state.selected.get(key) ?? {
                menuId: menu.id,
                menuName: menu.name,
                optionId: option?.id ?? null,
                optionName: option?.name ?? null,
                qty: 0,
                unitPrice: Number(menu.price || 0) + Number(option?.price_delta || 0),
                notes: '',
            };
        }

        function updateSelection(menu, option, qty) {
            const normalizedQty = Math.max(0, Number(qty || 0));
            const key = getSelectionKey(menu.id, option?.id ?? null);
            const current = cloneSelection(menu, option);

            if (normalizedQty <= 0) {
                state.selected.delete(key);
            } else {
                current.qty = normalizedQty;
                state.selected.set(key, current);
            }

            refreshCart();
            renderMenus();
        }

        function updateSelectionNotes(menu, option, notes) {
            const key = getSelectionKey(menu.id, option?.id ?? null);
            const current = cloneSelection(menu, option);

            if (current.qty <= 0) {
                return;
            }

            current.notes = notes;
            state.selected.set(key, current);
        }

        function currentQty(menuId, optionId = null) {
            return state.selected.get(getSelectionKey(menuId, optionId))?.qty ?? 0;
        }

        function currentNotes(menuId, optionId = null) {
            return state.selected.get(getSelectionKey(menuId, optionId))?.notes ?? '';
        }

        function allMenus() {
            return state.categories.flatMap((category) => (category.menus || []).map((menu) => ({
                ...menu,
                category_name: category.name,
                category_id: category.id,
            })));
        }

        function filteredMenus() {
            const query = state.query.trim().toLowerCase();
            return allMenus().filter((menu) => {
                const categoryMatch =
                    state.selectedCategory === 'Semua' ||
                    (menu.category_name || '').toLowerCase() === state.selectedCategory.toLowerCase();

                if (!categoryMatch) {
                    return false;
                }

                if (!query) {
                    return true;
                }

                return [
                    menu.name,
                    menu.sku,
                    menu.category_name,
                    menu.description,
                ].filter(Boolean).some((value) => value.toLowerCase().includes(query));
            });
        }

        function renderCategoryChips() {
            const categories = ['Semua', ...state.categories.map((category) => category.name)];
            categoryChips.innerHTML = '';

            categories.forEach((category) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `category-chip${category === state.selectedCategory ? ' active' : ''}`;
                button.textContent = category;
                button.addEventListener('click', () => {
                    state.selectedCategory = category;
                    renderCategoryChips();
                    renderMenus();
                });
                categoryChips.appendChild(button);
            });
        }

        function createQtyComposer(menu, option = null, disabled = false) {
            const qty = currentQty(menu.id, option?.id ?? null);
            const wrapper = document.createElement('div');

            const row = document.createElement('div');
            row.className = 'qty-row';

            const minus = document.createElement('button');
            minus.type = 'button';
            minus.className = 'qty-btn';
            minus.textContent = '−';
            minus.disabled = disabled || qty <= 0;
            minus.addEventListener('click', () => updateSelection(menu, option, qty - 1));

            const input = document.createElement('input');
            input.className = 'qty-input';
            input.type = 'text';
            input.inputMode = 'numeric';
            input.value = qty > 0 ? String(qty) : '';
            input.placeholder = '0';
            input.disabled = disabled;
            input.addEventListener('input', (event) => {
                const digitsOnly = event.target.value.replace(/[^0-9]/g, '');
                event.target.value = digitsOnly;
                updateSelection(menu, option, Number(digitsOnly || 0));
            });

            const plus = document.createElement('button');
            plus.type = 'button';
            plus.className = 'qty-btn';
            plus.textContent = '+';
            plus.disabled = disabled;
            plus.addEventListener('click', () => updateSelection(menu, option, qty + 1));

            row.append(minus, input, plus);
            wrapper.appendChild(row);

            if (qty > 0 && !disabled) {
                const note = document.createElement('div');
                note.className = 'note-field';

                const noteInput = document.createElement('input');
                noteInput.type = 'text';
                noteInput.placeholder = option ? 'Catatan untuk varian ini' : 'Catatan untuk menu ini';
                noteInput.value = currentNotes(menu.id, option?.id ?? null);
                noteInput.addEventListener('input', (event) => {
                    updateSelectionNotes(menu, option, event.target.value);
                });

                note.appendChild(noteInput);
                wrapper.appendChild(note);
            }

            return wrapper;
        }

        function renderMenus() {
            const menus = filteredMenus();
            menuContainer.innerHTML = '';

            const readyMenusCount = allMenus().filter((menu) => {
                const options = Array.isArray(menu.options) ? menu.options : [];
                const optionReady = !options.length || options.some((option) => option.is_available && option.is_active);
                return optionReady;
            }).length;
            const unavailableMenusCount = Math.max(allMenus().length - readyMenusCount, 0);

            readyCount.textContent = `${readyMenusCount} menu`;
            unavailableCount.textContent = `${unavailableMenusCount} menu`;
            unavailableStat.style.display = unavailableMenusCount > 0 ? 'block' : 'none';

            menuSummary.textContent = `${menus.length} menu siap dipesan`;

            if (!menus.length) {
                menuEmpty.classList.add('show');
                return;
            }

            menuEmpty.classList.remove('show');

            menus.forEach((menu) => {
                const options = Array.isArray(menu.options) ? menu.options : [];
                const hasOptions = options.length > 0;
                const selectedTotal = hasOptions
                    ? options.reduce((sum, option) => sum + currentQty(menu.id, option.id), 0)
                    : currentQty(menu.id);
                const canOrder = !hasOptions || options.some((option) => option.is_available && option.is_active);

                const card = document.createElement('article');
                card.className = `menu-card${canOrder ? '' : ' disabled'}`;

                const cover = document.createElement('div');
                cover.className = 'menu-cover';
                if (menu.image_url) {
                    const image = document.createElement('img');
                    image.src = menu.image_url;
                    image.alt = menu.name;
                    cover.appendChild(image);
                } else {
                    const fallback = document.createElement('div');
                    fallback.className = 'menu-cover-fallback';
                    fallback.textContent = menu.station_type === 'BAR' ? '☕' : '🍽';
                    cover.appendChild(fallback);
                }

                const body = document.createElement('div');
                body.className = 'menu-body';

                if (!canOrder) {
                    const unavailable = document.createElement('div');
                    unavailable.className = 'menu-unavailable';
                    unavailable.textContent = 'Menu habis atau ditutup';
                    body.appendChild(unavailable);
                }

                const title = document.createElement('h3');
                title.className = 'menu-title';
                title.textContent = menu.name;
                body.appendChild(title);

                const chips = document.createElement('div');
                chips.className = 'menu-chip-wrap';
                chips.innerHTML = `
                    <span class="info-chip">${menu.category_name ?? 'Tanpa kategori'}</span>
                    <span class="info-chip">${menu.station_type === 'BAR' ? 'Bar' : 'Dapur'}</span>
                `;
                body.appendChild(chips);

                const priceRow = document.createElement('div');
                priceRow.className = 'price-row';

                const price = document.createElement('span');
                price.className = 'price-pill';
                price.textContent = `Rp ${currency(menu.price)}`;

                const selectedPill = document.createElement('span');
                selectedPill.className = `selected-pill${selectedTotal > 0 ? ' active' : ''}`;
                selectedPill.textContent = selectedTotal > 0 ? `${selectedTotal} item dipilih` : 'Belum dipilih';

                priceRow.append(price, selectedPill);
                body.appendChild(priceRow);

                if (menu.description) {
                    const description = document.createElement('p');
                    description.className = 'menu-description';
                    description.textContent = menu.description;
                    body.appendChild(description);
                }

                if (hasOptions) {
                    const variantStack = document.createElement('div');
                    variantStack.className = 'variant-stack';

                    options.forEach((option) => {
                        const optionAvailable = option.is_available && option.is_active;
                        const variantCard = document.createElement('div');
                        variantCard.className = `variant-card${optionAvailable ? '' : ' unavailable'}`;

                        const top = document.createElement('div');
                        top.className = 'variant-top';

                        const left = document.createElement('div');
                        const extraPrice = Number(option.price_delta || 0);
                        left.innerHTML = `
                            <strong>${option.name}</strong>
                            <small>${extraPrice > 0 ? `Tambahan Rp ${currency(extraPrice)}` : 'Tanpa biaya tambahan'}</small>
                        `;

                        top.appendChild(left);

                        if (!optionAvailable) {
                            const badge = document.createElement('span');
                            badge.className = 'variant-badge';
                            badge.textContent = 'Habis';
                            top.appendChild(badge);
                        }

                        variantCard.appendChild(top);
                        variantCard.appendChild(createQtyComposer(menu, option, !optionAvailable));
                        variantStack.appendChild(variantCard);
                    });

                    body.appendChild(variantStack);
                } else {
                    body.appendChild(createQtyComposer(menu, null, !canOrder));
                }

                card.append(cover, body);
                menuContainer.appendChild(card);
            });
        }

        function refreshCart() {
            const items = Array.from(state.selected.values());
            const totalQty = items.reduce((sum, item) => sum + item.qty, 0);
            const totalPrice = items.reduce((sum, item) => sum + (item.qty * item.unitPrice), 0);

            checkoutButton.disabled = totalQty === 0;
            checkoutButton.textContent = `Kirim ${totalQty} item | Rp ${currency(totalPrice)}`;
            cartNote.textContent = totalQty === 0
                ? 'Pilih menu terlebih dahulu.'
                : `Perkiraan total Rp ${currency(totalPrice)}.`;
        }

        async function loadMenu() {
            clearFeedback();

            try {
                const response = await fetch(`${apiBase}/qr-menu/${tableCode}`);
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Menu belum dapat dimuat.');
                }

                state.table = payload.data.table;
                state.categories = (payload.data.categories || []).map((category) => ({
                    ...category,
                    menus: (category.menus || []).map((menu) => ({
                        ...menu,
                        category_name: category.name,
                    })),
                }));

                renderCategoryChips();
                renderMenus();
                refreshCart();
            } catch (error) {
                showError(error.message || 'Menu belum dapat dimuat.');
            }
        }

        async function submitOrder() {
            clearFeedback();

            const customerName = document.getElementById('customerName').value.trim();
            const customerPhone = document.getElementById('customerPhone').value.trim();
            const guestCount = Number(document.getElementById('guestCount').value || 1);
            const notes = document.getElementById('orderNotes').value.trim();
            const items = Array.from(state.selected.values()).map((item) => ({
                menu_id: item.menuId,
                menu_option_id: item.optionId,
                qty: item.qty,
                notes: item.notes?.trim() ? item.notes.trim() : null,
            }));

            if (!customerName) {
                showError('Nama pelanggan wajib diisi.');
                return;
            }

            if (!items.length) {
                showError('Pilih minimal satu menu.');
                return;
            }

            checkoutButton.disabled = true;
            checkoutButton.textContent = 'Mengirim...';

            try {
                const response = await fetch(`${apiBase}/qr-menu/${tableCode}/checkout`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        customer_name: customerName,
                        customer_phone: customerPhone || null,
                        guest_count: guestCount,
                        notes: notes || null,
                        items,
                    }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Pesanan belum dapat dikirim.');
                }

                state.selected.clear();
                document.getElementById('orderNotes').value = '';
                renderMenus();
                refreshCart();
                showSuccess('Pesanan berhasil dikirim. Silakan tunggu konfirmasi dari restoran.');
            } catch (error) {
                showError(error.message || 'Pesanan belum dapat dikirim.');
            } finally {
                refreshCart();
            }
        }

        searchInput.addEventListener('input', (event) => {
            state.query = event.target.value || '';
            renderMenus();
        });

        checkoutButton.addEventListener('click', submitOrder);
        loadMenu();
    </script>
</body>
</html>

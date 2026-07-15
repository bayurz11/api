<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Menu Meja {{ $tableCode }} | Warung Babeh</title>
    <style>
        :root {
            --green: #0D6B3A;
            --deep: #004B36;
            --gold: #E3B51C;
            --cream: #FFF6D8;
            --white: #FFFFFF;
            --muted: #6E776C;
            --line: #FFE8A3;
            --danger: #B0413E;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #fffaf0 0%, var(--cream) 100%);
            color: var(--deep);
        }
        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px 16px 100px;
        }
        .hero {
            background: linear-gradient(135deg, var(--green), var(--deep));
            color: var(--white);
            border-radius: 28px;
            padding: 24px 20px;
            box-shadow: 0 18px 42px rgba(0, 75, 54, 0.18);
        }
        .hero h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.1;
        }
        .hero p {
            margin: 10px 0 0;
            line-height: 1.5;
            color: rgba(255,255,255,0.92);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            margin-bottom: 14px;
            font-weight: 700;
        }
        .panel {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 18px;
            margin-top: 18px;
            box-shadow: 0 10px 28px rgba(0, 75, 54, 0.06);
        }
        .section-title {
            margin: 0 0 8px;
            font-size: 24px;
        }
        .section-subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.45;
        }
        .customer-grid,
        .menu-grid {
            display: grid;
            gap: 14px;
        }
        .customer-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            margin-top: 16px;
        }
        .menu-grid {
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            margin-top: 16px;
        }
        .field,
        .menu-card {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: #fffdf6;
        }
        .field {
            padding: 14px;
        }
        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .field input,
        .field textarea,
        .field select {
            width: 100%;
            border: 1px solid #f0db96;
            border-radius: 14px;
            padding: 12px 14px;
            font: inherit;
            color: var(--deep);
            background: var(--white);
        }
        .field textarea { min-height: 92px; resize: vertical; }
        .menu-card {
            overflow: hidden;
        }
        .menu-header {
            padding: 14px 16px 8px;
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 10px;
        }
        .menu-name {
            margin: 0;
            font-size: 20px;
        }
        .price {
            color: var(--gold);
            font-weight: 800;
            white-space: nowrap;
        }
        .menu-body {
            padding: 0 16px 16px;
        }
        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            background: #eef7f0;
            color: var(--green);
            font-size: 13px;
            font-weight: 700;
        }
        .qty-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
        }
        .qty-btn {
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 14px;
            background: #e5f4ea;
            color: var(--green);
            font-size: 24px;
            cursor: pointer;
        }
        .qty-value {
            min-width: 48px;
            text-align: center;
            font-size: 22px;
            font-weight: 800;
        }
        .option-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }
        .option-card {
            border: 1px solid #f4e2aa;
            border-radius: 18px;
            padding: 12px;
            background: #fffef9;
        }
        .option-top {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }
        .option-top strong {
            font-size: 15px;
        }
        .option-bad {
            color: var(--danger);
            font-weight: 800;
        }
        .cart-bar {
            position: fixed;
            left: 16px;
            right: 16px;
            bottom: 16px;
            max-width: 960px;
            margin: 0 auto;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(14px);
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 14px;
            display: flex;
            gap: 12px;
            align-items: center;
            box-shadow: 0 16px 34px rgba(0, 75, 54, 0.12);
        }
        .cart-summary {
            flex: 1;
        }
        .cart-summary strong {
            display: block;
            font-size: 18px;
        }
        .cart-summary span {
            color: var(--muted);
            font-size: 13px;
        }
        .button {
            border: none;
            border-radius: 16px;
            padding: 14px 18px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }
        .button-primary {
            background: var(--green);
            color: var(--white);
        }
        .button-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .alert {
            padding: 14px 16px;
            border-radius: 18px;
            margin-top: 16px;
            line-height: 1.45;
            display: none;
        }
        .alert.show { display: block; }
        .alert-error {
            background: #fff1ef;
            color: var(--danger);
            border: 1px solid #f6c8c0;
        }
        .alert-success {
            background: #edf8f0;
            color: var(--green);
            border: 1px solid #cde7d4;
        }
        .empty {
            text-align: center;
            padding: 28px 18px;
            color: var(--muted);
        }
        @media (max-width: 640px) {
            .hero h1 { font-size: 26px; }
            .cart-bar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="hero">
        <div class="badge">Meja {{ $tableCode }}</div>
        <h1>Pesan langsung dari meja</h1>
        <p>Pilih menu, isi nama pelanggan, lalu kirim pesanan. Tim restoran akan memproses pesanan Anda setelah diterima.</p>
    </section>

    <section class="panel">
        <h2 class="section-title">Data Pemesanan</h2>
        <p class="section-subtitle">Isi data singkat agar restoran dapat menyiapkan pesanan dengan tepat.</p>
        <div class="customer-grid">
            <div class="field">
                <label for="customerName">Nama pelanggan</label>
                <input id="customerName" type="text" placeholder="Contoh: Bayu">
            </div>
            <div class="field">
                <label for="customerPhone">Nomor telepon</label>
                <input id="customerPhone" type="text" placeholder="Opsional">
            </div>
            <div class="field">
                <label for="guestCount">Jumlah tamu</label>
                <input id="guestCount" type="number" min="1" value="1">
            </div>
            <div class="field">
                <label for="orderNotes">Catatan umum</label>
                <textarea id="orderNotes" placeholder="Contoh: mohon alat makan tambahan"></textarea>
            </div>
        </div>
        <div id="feedbackError" class="alert alert-error"></div>
        <div id="feedbackSuccess" class="alert alert-success"></div>
    </section>

    <section class="panel">
        <h2 class="section-title">Daftar Menu</h2>
        <p class="section-subtitle">Pilih menu yang tersedia. Jika ada varian, pilih bagian yang diinginkan.</p>
        <div id="menuContainer" class="menu-grid"></div>
        <div id="menuEmpty" class="empty" style="display:none;">Menu belum tersedia untuk meja ini.</div>
    </section>
</div>

<div class="cart-bar">
    <div class="cart-summary">
        <strong id="cartTotal">0 item</strong>
        <span id="cartHint">Pilih menu terlebih dahulu</span>
    </div>
    <button id="checkoutButton" class="button button-primary" disabled>Kirim Pesanan</button>
</div>

<script>
    const tableCode = @json($tableCode);
    const apiBase = `${window.location.origin}/api/v1`;
    const state = {
        table: null,
        categories: [],
        selected: new Map(),
    };

    const currency = (value) => new Intl.NumberFormat('id-ID').format(Number(value || 0));
    const menuContainer = document.getElementById('menuContainer');
    const menuEmpty = document.getElementById('menuEmpty');
    const cartTotal = document.getElementById('cartTotal');
    const cartHint = document.getElementById('cartHint');
    const checkoutButton = document.getElementById('checkoutButton');
    const feedbackError = document.getElementById('feedbackError');
    const feedbackSuccess = document.getElementById('feedbackSuccess');

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

    function updateSelection(menu, option, qty) {
        const key = getSelectionKey(menu.id, option?.id ?? null);
        if (qty <= 0) {
            state.selected.delete(key);
        } else {
            state.selected.set(key, {
                menuId: menu.id,
                menuName: menu.name,
                optionId: option?.id ?? null,
                optionName: option?.name ?? null,
                qty,
                unitPrice: Number(menu.price) + Number(option?.price_delta ?? 0),
            });
        }
        refreshCart();
        renderMenus();
    }

    function currentQty(menuId, optionId = null) {
        return state.selected.get(getSelectionKey(menuId, optionId))?.qty ?? 0;
    }

    function refreshCart() {
        const items = Array.from(state.selected.values());
        const totalQty = items.reduce((sum, item) => sum + item.qty, 0);
        const totalPrice = items.reduce((sum, item) => sum + (item.qty * item.unitPrice), 0);

        cartTotal.textContent = `${totalQty} item`;
        cartHint.textContent = totalQty === 0
            ? 'Pilih menu terlebih dahulu'
            : `Perkiraan total Rp ${currency(totalPrice)}`;
        checkoutButton.disabled = totalQty === 0;
    }

    function createQtyRow(menu, option = null) {
        const qty = currentQty(menu.id, option?.id ?? null);
        const wrapper = document.createElement('div');
        wrapper.className = 'qty-row';

        const minus = document.createElement('button');
        minus.className = 'qty-btn';
        minus.type = 'button';
        minus.textContent = '−';
        minus.onclick = () => updateSelection(menu, option, qty - 1);

        const value = document.createElement('div');
        value.className = 'qty-value';
        value.textContent = qty;

        const plus = document.createElement('button');
        plus.className = 'qty-btn';
        plus.type = 'button';
        plus.textContent = '+';
        plus.onclick = () => updateSelection(menu, option, qty + 1);

        wrapper.append(minus, value, plus);
        return wrapper;
    }

    function renderMenus() {
        const menus = state.categories.flatMap(category => category.menus || []);
        menuContainer.innerHTML = '';

        if (menus.length === 0) {
            menuEmpty.style.display = 'block';
            return;
        }

        menuEmpty.style.display = 'none';

        menus.forEach((menu) => {
            const card = document.createElement('article');
            card.className = 'menu-card';

            const header = document.createElement('div');
            header.className = 'menu-header';

            const titleWrap = document.createElement('div');
            const title = document.createElement('h3');
            title.className = 'menu-name';
            title.textContent = menu.name;
            titleWrap.appendChild(title);

            const price = document.createElement('div');
            price.className = 'price';
            price.textContent = `Rp ${currency(menu.price)}`;
            header.append(titleWrap, price);

            const body = document.createElement('div');
            body.className = 'menu-body';

            const chips = document.createElement('div');
            chips.className = 'chips';
            chips.innerHTML = `
                <span class="chip">${menu.category_name ?? 'Menu'}</span>
                <span class="chip">${menu.station_type === 'BAR' ? 'Bar' : 'Dapur'}</span>
            `;

            body.appendChild(chips);

            if (menu.description) {
                const desc = document.createElement('p');
                desc.className = 'section-subtitle';
                desc.textContent = menu.description;
                body.appendChild(desc);
            }

            if (Array.isArray(menu.options) && menu.options.length > 0) {
                const optionList = document.createElement('div');
                optionList.className = 'option-list';

                menu.options.forEach((option) => {
                    const optionCard = document.createElement('div');
                    optionCard.className = 'option-card';
                    const top = document.createElement('div');
                    top.className = 'option-top';

                    const left = document.createElement('div');
                    left.innerHTML = `<strong>${option.name}</strong><div class="section-subtitle">${option.price_delta > 0 ? `Tambahan Rp ${currency(option.price_delta)}` : 'Tanpa biaya tambahan'}</div>`;

                    top.appendChild(left);

                    if (!option.is_available) {
                        const badge = document.createElement('div');
                        badge.className = 'option-bad';
                        badge.textContent = 'Habis';
                        top.appendChild(badge);
                    }

                    optionCard.appendChild(top);

                    if (option.is_available) {
                        optionCard.appendChild(createQtyRow(menu, option));
                    }

                    optionList.appendChild(optionCard);
                });

                body.appendChild(optionList);
            } else {
                body.appendChild(createQtyRow(menu));
            }

            card.append(header, body);
            menuContainer.appendChild(card);
        });
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
            state.categories = payload.data.categories.map((category) => ({
                ...category,
                menus: (category.menus || []).map((menu) => ({
                    ...menu,
                    category_name: category.name,
                })),
            }));

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
        }));

        if (!customerName) {
            showError('Nama pelanggan wajib diisi.');
            return;
        }

        if (items.length === 0) {
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
            renderMenus();
            refreshCart();
            document.getElementById('orderNotes').value = '';
            showSuccess('Pesanan berhasil dikirim. Silakan tunggu konfirmasi dari restoran.');
        } catch (error) {
            showError(error.message || 'Pesanan belum dapat dikirim.');
        } finally {
            checkoutButton.disabled = false;
            checkoutButton.textContent = 'Kirim Pesanan';
        }
    }

    checkoutButton.addEventListener('click', submitOrder);
    loadMenu();
</script>
</body>
</html>

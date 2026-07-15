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
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 800;
            color: rgba(0, 75, 54, 0.82);
        }

        .menu-cover-fallback .icon {
            font-size: 40px;
            line-height: 1;
        }

        .menu-cover-fallback .sku {
            font-size: 18px;
            letter-spacing: 0.4px;
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

        .variant-composer {
            margin-top: 2px;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: linear-gradient(180deg, #F8FDF8 0%, #FFFDF7 100%);
        }

        .variant-title {
            margin: 0 0 12px;
            font-size: 17px;
            font-weight: 900;
            color: var(--deep);
        }

        .variant-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .variant-chip {
            border: 1px solid var(--line);
            background: var(--white);
            color: var(--deep);
            padding: 10px 16px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
        }

        .variant-chip.active {
            background: var(--green);
            border-color: var(--green);
            color: var(--white);
            box-shadow: 0 10px 18px rgba(13, 107, 58, 0.18);
        }

        .variant-chip.unavailable {
            color: var(--danger);
            border-color: #F7B8AE;
            background: #FFF9F8;
            cursor: default;
        }

        .variant-panel {
            margin-top: 14px;
            padding: 14px;
            border: 1px solid #F4E2AA;
            border-radius: 22px;
            background: var(--white);
        }

        .variant-panel h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 900;
            color: var(--deep);
        }

        .variant-panel p {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.4;
            font-size: 12px;
        }

        .variant-summary {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .variant-summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--soft);
            color: var(--deep);
            font-size: 13px;
            font-weight: 800;
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

        .qty-panel {
            margin-top: 10px;
            padding: 12px;
            border-radius: 18px;
            background: #FFFCF1;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .qty-label {
            flex: 1;
            font-size: 14px;
            font-weight: 800;
            color: var(--deep);
        }

        .qty-panel .qty-row {
            margin-top: 0;
        }

        .menu-ready {
            width: 100%;
            padding: 12px;
            border-radius: 16px;
            background: var(--soft);
            color: var(--deep);
            font-size: 13px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
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

        .cart-bar.hidden {
            display: none;
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

        .hidden {
            display: none !important;
        }

        .success-screen {
            display: none;
            margin-top: 18px;
        }

        .success-screen.show {
            display: block;
        }

        .success-hero {
            padding: 22px 20px;
            border-radius: 24px;
            background: linear-gradient(135deg, var(--green), var(--deep));
            color: var(--white);
            box-shadow: 0 18px 42px rgba(0, 75, 54, 0.18);
        }

        .success-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 14px;
            font-weight: 800;
        }

        .success-hero h2 {
            margin: 14px 0 0;
            font-size: clamp(26px, 4vw, 34px);
            line-height: 1.1;
            font-weight: 900;
        }

        .success-hero p {
            margin: 10px 0 0;
            color: rgba(255, 255, 255, 0.92);
            line-height: 1.55;
            max-width: 760px;
        }

        .success-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 16px;
            margin-top: 18px;
        }

        .success-card {
            grid-column: span 12;
            padding: 18px;
            border-radius: 24px;
            border: 1px solid var(--line);
            background: var(--white);
            box-shadow: var(--shadow);
        }

        .success-card h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: var(--deep);
        }

        .success-card p {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .success-card--summary {
            grid-column: span 7;
        }

        .success-card--items {
            grid-column: span 5;
        }

        .success-status-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .success-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 800;
            background: var(--soft);
            color: var(--deep);
        }

        .success-status-pill.pending {
            background: #FFF5CF;
            color: #8B5E34;
        }

        .success-status-pill.approved {
            background: #EDF8F0;
            color: var(--green);
        }

        .success-status-pill.rejected {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .success-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .success-meta-item {
            padding: 14px 16px;
            border-radius: 18px;
            background: var(--soft-2);
        }

        .success-meta-item strong {
            display: block;
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .success-meta-item span {
            display: block;
            font-size: 18px;
            font-weight: 900;
            color: var(--deep);
        }

        .success-next-steps {
            margin-top: 16px;
            padding: 16px;
            border-radius: 20px;
            background: #FFFDF5;
            border: 1px solid var(--line);
        }

        .success-timeline {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .timeline-step {
            position: relative;
            min-height: 124px;
            padding: 16px;
            border-radius: 20px;
            border: 1px solid #E9E9DB;
            background: #FAFAF4;
            transition: border-color 160ms ease, background 160ms ease, box-shadow 160ms ease;
        }

        .timeline-step.current {
            border-color: var(--line);
            background: #FFFDF5;
            box-shadow: 0 10px 22px rgba(0, 75, 54, 0.08);
        }

        .timeline-step.done {
            border-color: #CDE7D4;
            background: #F6FCF7;
        }

        .timeline-step.rejected {
            border-color: #F5CEC7;
            background: #FFF7F4;
        }

        .timeline-step-badge {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 900;
            background: #E9E9DB;
            color: var(--muted);
        }

        .timeline-step.done .timeline-step-badge,
        .timeline-step.current .timeline-step-badge {
            background: var(--green);
            color: var(--white);
        }

        .timeline-step.rejected .timeline-step-badge {
            background: var(--danger);
            color: var(--white);
        }

        .timeline-step-title {
            margin-top: 12px;
            font-size: 15px;
            font-weight: 800;
            color: var(--deep);
        }

        .timeline-step-desc {
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.45;
            color: var(--muted);
        }

        .success-next-steps strong {
            display: block;
            margin-bottom: 8px;
            font-size: 15px;
            color: var(--deep);
        }

        .success-next-steps ul {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            line-height: 1.55;
        }

        .success-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 16px;
        }

        .success-item {
            padding: 14px 16px;
            border-radius: 18px;
            background: #FFFDF5;
            border: 1px solid var(--line);
        }

        .success-item-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: flex-start;
        }

        .success-item-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--deep);
        }

        .success-item-qty {
            flex: none;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--soft);
            font-size: 13px;
            font-weight: 800;
            color: var(--green);
        }

        .success-item-note {
            margin-top: 8px;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
        }

        .success-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .secondary-button {
            border: 1px solid var(--line);
            background: var(--white);
            color: var(--deep);
            padding: 14px 18px;
            border-radius: 18px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
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
                padding: 14px 12px 132px;
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
                gap: 14px;
            }

            .success-card--summary,
            .success-card--items {
                grid-column: span 12;
            }

            .success-meta {
                grid-template-columns: 1fr;
            }

            .success-timeline {
                grid-template-columns: 1fr;
            }

            .menu-cover {
                height: 164px;
            }

            .menu-body {
                padding: 14px;
            }

            .price-row {
                flex-wrap: wrap;
            }

            .selected-pill {
                margin-left: 0;
            }

            .variant-composer {
                padding: 14px;
            }

            .variant-selector {
                gap: 8px;
            }

            .variant-chip {
                padding: 9px 14px;
                font-size: 13px;
            }

            .qty-panel {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .qty-label {
                flex: none;
            }

            .qty-row {
                width: 100%;
                gap: 10px;
            }

            .qty-btn {
                width: 44px;
                height: 44px;
                border-radius: 14px;
                font-size: 22px;
                flex: 0 0 44px;
            }

            .qty-input {
                width: 100%;
                min-width: 0;
                flex: 1;
                font-size: 20px;
            }

            .cart-bar {
                left: 12px;
                right: 12px;
                bottom: 12px;
            }

            .cart-inner {
                width: 100%;
            }

            .submit-button {
                padding: 15px 16px;
                font-size: 16px;
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

        <section id="successScreen" class="success-screen">
            <div class="success-hero">
                <div id="successBadge" class="success-badge">Pesanan berhasil masuk</div>
                <h2>Pesanan Anda sudah diterima</h2>
                <p id="successSubtitle">Tim restoran sedang meninjau pesanan Anda sebelum diteruskan ke proses berikutnya.</p>
            </div>

            <div class="success-grid">
                <section class="success-card success-card--summary">
                    <h3>Ringkasan Pesanan</h3>
                    <p id="successDescription">Simpan ringkasan ini agar mudah dicek bila Anda ingin menanyakan status ke tim restoran.</p>

                    <div class="success-status-row">
                        <div id="successStatusPill" class="success-status-pill pending">Menunggu konfirmasi restoran</div>
                    </div>

                    <div id="successTimeline" class="success-timeline"></div>

                    <div class="success-meta">
                        <div class="success-meta-item">
                            <strong>Nomor pesanan</strong>
                            <span id="successOrderNo">-</span>
                        </div>
                        <div class="success-meta-item">
                            <strong>Meja</strong>
                            <span id="successTable">-</span>
                        </div>
                        <div class="success-meta-item">
                            <strong>Nama pelanggan</strong>
                            <span id="successCustomer">-</span>
                        </div>
                        <div class="success-meta-item">
                            <strong>Jumlah item</strong>
                            <span id="successItemsCount">0 item</span>
                        </div>
                        <div class="success-meta-item">
                            <strong>Total perkiraan</strong>
                            <span id="successGrandTotal">Rp 0</span>
                        </div>
                        <div class="success-meta-item">
                            <strong>Waktu kirim</strong>
                            <span id="successSubmittedAt">-</span>
                        </div>
                        <div class="success-meta-item">
                            <strong>Bill</strong>
                            <span id="successBillNo">-</span>
                        </div>
                        <div class="success-meta-item">
                            <strong>Status proses</strong>
                            <span id="successProcessLabel">Menunggu konfirmasi</span>
                        </div>
                    </div>

                    <div class="success-next-steps">
                        <strong>Langkah selanjutnya</strong>
                        <ul id="successNextSteps">
                            <li>Pesanan akan dicek oleh tim restoran.</li>
                            <li>Setelah disetujui, pesanan akan masuk ke tagihan meja Anda.</li>
                            <li>Jika ingin menambah menu lagi, tekan tombol Pesan Lagi.</li>
                        </ul>
                    </div>

                    <div class="success-actions">
                        <button id="refreshStatusButton" class="submit-button" type="button">Cek Status Pesanan</button>
                        <button id="backToMenuButton" class="secondary-button" type="button">Pesan Lagi</button>
                    </div>
                </section>

                <section class="success-card success-card--items">
                    <h3>Item yang Dikirim</h3>
                    <p>Periksa kembali daftar menu yang baru saja Anda kirim dari meja ini.</p>
                    <div id="successItems" class="success-items"></div>
                </section>
            </div>
        </section>

        <div id="orderingFlow">
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
                        <span class="search-icon">&#128269;</span>
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
    </div>

    <div id="cartBar" class="cart-bar hidden">
        <div class="cart-inner">
            <button id="checkoutButton" class="submit-button" type="button" disabled>Kirim 0 item | Rp 0</button>
            <div id="cartNote" class="cart-note">Pilih menu terlebih dahulu.</div>
        </div>
    </div>

    <script>
        const tableCode = @json($tableCode);
        const apiBase = `${window.location.origin}/api/v1`;
        const guestTokenStorageKey = `warung-babeh-qr-order:${tableCode}`;
        const state = {
            table: null,
            categories: [],
            selectedCategory: 'Semua',
            query: '',
            selected: new Map(),
            activeOptionByMenu: {},
            lastGuestToken: null,
            statusTimer: null,
        };

        const menuContainer = document.getElementById('menuContainer');
        const menuEmpty = document.getElementById('menuEmpty');
        const categoryChips = document.getElementById('categoryChips');
        const cartBar = document.getElementById('cartBar');
        const checkoutButton = document.getElementById('checkoutButton');
        const cartNote = document.getElementById('cartNote');
        const feedbackError = document.getElementById('feedbackError');
        const feedbackSuccess = document.getElementById('feedbackSuccess');
        const searchInput = document.getElementById('searchInput');
        const readyCount = document.getElementById('readyCount');
        const unavailableStat = document.getElementById('unavailableStat');
        const unavailableCount = document.getElementById('unavailableCount');
        const menuSummary = document.getElementById('menuSummary');
        const orderingFlow = document.getElementById('orderingFlow');
        const successScreen = document.getElementById('successScreen');
        const successBadge = document.getElementById('successBadge');
        const successSubtitle = document.getElementById('successSubtitle');
        const successDescription = document.getElementById('successDescription');
        const successStatusPill = document.getElementById('successStatusPill');
        const successOrderNo = document.getElementById('successOrderNo');
        const successTable = document.getElementById('successTable');
        const successCustomer = document.getElementById('successCustomer');
        const successItemsCount = document.getElementById('successItemsCount');
        const successGrandTotal = document.getElementById('successGrandTotal');
        const successSubmittedAt = document.getElementById('successSubmittedAt');
        const successBillNo = document.getElementById('successBillNo');
        const successProcessLabel = document.getElementById('successProcessLabel');
        const successTimeline = document.getElementById('successTimeline');
        const successItems = document.getElementById('successItems');
        const successNextSteps = document.getElementById('successNextSteps');
        const refreshStatusButton = document.getElementById('refreshStatusButton');
        const backToMenuButton = document.getElementById('backToMenuButton');

        function currency(value) {
            return new Intl.NumberFormat('id-ID').format(Number(value || 0));
        }

        function clearStatusTimer() {
            if (state.statusTimer) {
                window.clearInterval(state.statusTimer);
                state.statusTimer = null;
            }
        }

        function persistGuestToken(token) {
            if (!token) {
                sessionStorage.removeItem(guestTokenStorageKey);
                return;
            }

            sessionStorage.setItem(guestTokenStorageKey, token);
        }

        function restoreGuestToken() {
            const saved = sessionStorage.getItem(guestTokenStorageKey);
            if (saved && saved.trim() !== '') {
                state.lastGuestToken = saved.trim();
            }
        }

        function formatDateTime(value) {
            if (!value) {
                return '-';
            }

            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return '-';
            }

            return new Intl.DateTimeFormat('id-ID', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }).format(date);
        }

        function mapOrderStatus(status) {
            switch ((status || '').toUpperCase()) {
                case 'APPROVED':
                    return {
                        label: 'Sudah diterima restoran',
                        badge: 'Pesanan diterima',
                        subtitle: 'Pesanan Anda sudah diteruskan ke tagihan meja dan sedang diproses oleh tim restoran.',
                        description: 'Anda tidak perlu mengirim ulang pesanan. Jika ingin menambah menu, tekan tombol Pesan Lagi.',
                        className: 'approved',
                    };
                case 'REJECTED':
                    return {
                        label: 'Pesanan perlu diperiksa ulang',
                        badge: 'Pesanan belum diproses',
                        subtitle: 'Pesanan ini belum bisa diteruskan oleh restoran. Silakan cek catatan atau hubungi petugas.',
                        description: 'Anda dapat kembali ke menu untuk membuat pesanan baru bila diperlukan.',
                        className: 'rejected',
                    };
                default:
                    return {
                        label: 'Menunggu konfirmasi restoran',
                        badge: 'Pesanan berhasil masuk',
                        subtitle: 'Tim restoran sedang meninjau pesanan Anda sebelum diteruskan ke proses berikutnya.',
                        description: 'Simpan ringkasan ini agar mudah dicek bila Anda ingin menanyakan status ke tim restoran.',
                        className: 'pending',
                    };
            }
        }

        function resolvePublicProgress(order) {
            const qrStatus = (order.status || '').toUpperCase();
            const orderStatus = (order.approved_order?.status || order.approvedOrder?.status || '').toUpperCase();
            const billStatus = (order.bill?.status || '').toUpperCase();

            if (qrStatus === 'REJECTED') {
                return {
                    processLabel: 'Pesanan perlu diperiksa ulang',
                    steps: [
                        { title: 'Pesanan masuk', description: 'Pesanan berhasil tercatat dari QR meja.', state: 'done' },
                        { title: 'Konfirmasi restoran', description: 'Pesanan belum bisa diteruskan dan perlu diperiksa ulang.', state: 'rejected' },
                        { title: 'Diproses', description: 'Tahap proses belum dimulai.', state: 'upcoming' },
                        { title: 'Selesai', description: 'Pesanan belum selesai.', state: 'upcoming' },
                    ],
                    final: true,
                };
            }

            if (qrStatus === 'PENDING') {
                return {
                    processLabel: 'Menunggu konfirmasi restoran',
                    steps: [
                        { title: 'Pesanan masuk', description: 'Pesanan berhasil dikirim dari meja Anda.', state: 'done' },
                        { title: 'Konfirmasi restoran', description: 'Tim restoran sedang memeriksa pesanan ini.', state: 'current' },
                        { title: 'Diproses', description: 'Pesanan akan mulai disiapkan setelah disetujui.', state: 'upcoming' },
                        { title: 'Selesai', description: 'Pesanan akan ditandai selesai setelah disajikan.', state: 'upcoming' },
                    ],
                    final: false,
                };
            }

            if (billStatus === 'PAID') {
                return {
                    processLabel: 'Pesanan selesai dan pembayaran tuntas',
                    steps: [
                        { title: 'Pesanan masuk', description: 'Pesanan tercatat dari QR meja.', state: 'done' },
                        { title: 'Konfirmasi restoran', description: 'Pesanan telah diterima restoran.', state: 'done' },
                        { title: 'Diproses', description: 'Pesanan sudah disiapkan dan diteruskan.', state: 'done' },
                        { title: 'Selesai', description: 'Pesanan selesai dan tagihan sudah dibayar.', state: 'done' },
                    ],
                    final: true,
                };
            }

            if (orderStatus === 'SERVED' || billStatus === 'SERVED') {
                return {
                    processLabel: 'Pesanan sudah diantar',
                    steps: [
                        { title: 'Pesanan masuk', description: 'Pesanan tercatat dari QR meja.', state: 'done' },
                        { title: 'Konfirmasi restoran', description: 'Pesanan telah diterima restoran.', state: 'done' },
                        { title: 'Diproses', description: 'Pesanan sudah disiapkan oleh tim restoran.', state: 'done' },
                        { title: 'Selesai', description: 'Pesanan sudah diantar ke meja Anda.', state: 'current' },
                    ],
                    final: false,
                };
            }

            if (orderStatus === 'READY' || billStatus === 'READY_TO_PAY') {
                return {
                    processLabel: 'Pesanan siap diantar',
                    steps: [
                        { title: 'Pesanan masuk', description: 'Pesanan tercatat dari QR meja.', state: 'done' },
                        { title: 'Konfirmasi restoran', description: 'Pesanan telah diterima restoran.', state: 'done' },
                        { title: 'Diproses', description: 'Pesanan sudah siap diteruskan ke meja Anda.', state: 'done' },
                        { title: 'Selesai', description: 'Menunggu penyerahan ke meja atau penyelesaian layanan.', state: 'current' },
                    ],
                    final: false,
                };
            }

            if (['ACCEPTED', 'COOKING', 'PREPARING', 'WAITING'].includes(orderStatus) || qrStatus === 'APPROVED') {
                return {
                    processLabel: 'Pesanan sedang diproses',
                    steps: [
                        { title: 'Pesanan masuk', description: 'Pesanan berhasil tercatat dari meja Anda.', state: 'done' },
                        { title: 'Konfirmasi restoran', description: 'Pesanan sudah diterima dan masuk ke tagihan meja.', state: 'done' },
                        { title: 'Diproses', description: 'Tim restoran sedang menyiapkan pesanan Anda.', state: 'current' },
                        { title: 'Selesai', description: 'Status ini akan berubah setelah pesanan disajikan.', state: 'upcoming' },
                    ],
                    final: false,
                };
            }

            return {
                processLabel: 'Status pesanan sedang diperbarui',
                steps: [
                    { title: 'Pesanan masuk', description: 'Pesanan berhasil tercatat dari meja Anda.', state: 'done' },
                    { title: 'Konfirmasi restoran', description: 'Status konfirmasi sedang diperbarui.', state: 'current' },
                    { title: 'Diproses', description: 'Proses akan tampil setelah data restoran diperbarui.', state: 'upcoming' },
                    { title: 'Selesai', description: 'Tahap akhir akan muncul setelah pesanan diantar.', state: 'upcoming' },
                ],
                final: false,
            };
        }

        function renderTimeline(order) {
            const progress = resolvePublicProgress(order);
            successProcessLabel.textContent = progress.processLabel;
            successTimeline.innerHTML = '';

            progress.steps.forEach((step, index) => {
                const card = document.createElement('div');
                card.className = `timeline-step ${step.state === 'upcoming' ? '' : step.state}`.trim();

                const badge = document.createElement('div');
                badge.className = 'timeline-step-badge';
                badge.textContent = step.state === 'done' ? '✓' : String(index + 1);

                const title = document.createElement('div');
                title.className = 'timeline-step-title';
                title.textContent = step.title;

                const desc = document.createElement('div');
                desc.className = 'timeline-step-desc';
                desc.textContent = step.description;

                card.append(badge, title, desc);
                successTimeline.appendChild(card);
            });

            return progress.final;
        }

        function totalItems(items) {
            return (items || []).reduce((sum, item) => sum + Number(item.qty || 0), 0);
        }

        function renderSuccessItems(items) {
            successItems.innerHTML = '';

            if (!items || !items.length) {
                const empty = document.createElement('div');
                empty.className = 'success-item';
                empty.textContent = 'Belum ada item yang tercatat.';
                successItems.appendChild(empty);
                return;
            }

            items.forEach((item) => {
                const card = document.createElement('div');
                card.className = 'success-item';

                const head = document.createElement('div');
                head.className = 'success-item-head';

                const titleWrap = document.createElement('div');
                const title = document.createElement('div');
                title.className = 'success-item-title';
                title.textContent = item.menu_name || item.menuName || 'Menu';
                titleWrap.appendChild(title);

                const price = document.createElement('div');
                price.className = 'success-item-note';
                price.textContent = `Rp ${currency(item.line_total || (Number(item.unit_price || 0) * Number(item.qty || 0)))}`;
                titleWrap.appendChild(price);

                const qty = document.createElement('div');
                qty.className = 'success-item-qty';
                qty.textContent = `${item.qty || 0} item`;

                head.append(titleWrap, qty);
                card.appendChild(head);

                if (item.notes) {
                    const note = document.createElement('div');
                    note.className = 'success-item-note';
                    note.textContent = `Catatan: ${item.notes}`;
                    card.appendChild(note);
                }

                successItems.appendChild(card);
            });
        }

        function renderNextSteps(order) {
            const status = (order.status || '').toUpperCase();
            const steps = [];

            if (status === 'APPROVED') {
                steps.push('Pesanan sudah diterima restoran dan masuk ke proses operasional.');
                if (order.bill && order.bill.bill_no) {
                    steps.push(`Pesanan ini sudah masuk ke tagihan ${order.bill.bill_no}.`);
                }
                steps.push('Jika ingin menambah menu lagi, Anda bisa kembali ke menu dan kirim pesanan tambahan.');
            } else if (status === 'REJECTED') {
                steps.push('Pesanan ini belum bisa diproses oleh restoran.');
                steps.push('Silakan hubungi petugas restoran atau buat pesanan baru bila diperlukan.');
            } else {
                steps.push('Pesanan akan dicek terlebih dahulu oleh tim restoran.');
                steps.push('Setelah disetujui, pesanan akan masuk ke tagihan meja Anda.');
                steps.push('Jika ingin menambah menu lagi, tekan tombol Pesan Lagi.');
            }

            successNextSteps.innerHTML = '';
            steps.forEach((text) => {
                const li = document.createElement('li');
                li.textContent = text;
                successNextSteps.appendChild(li);
            });
        }

        function showOrderingFlow() {
            clearStatusTimer();
            successScreen.classList.remove('show');
            orderingFlow.classList.remove('hidden');
            refreshCart();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function showSuccessScreen(order) {
            const statusInfo = mapOrderStatus(order.status);
            const items = order.items || [];
            const tableLabel = order.table && order.table.code
                ? `${order.table.code} | ${order.table.name || 'Meja'}`
                : (state.table && state.table.code ? `${state.table.code} | ${state.table.name || 'Meja'}` : '-');

            successBadge.textContent = statusInfo.badge;
            successSubtitle.textContent = statusInfo.subtitle;
            successDescription.textContent = statusInfo.description;
            successStatusPill.textContent = statusInfo.label;
            successStatusPill.className = `success-status-pill ${statusInfo.className}`;
            successOrderNo.textContent = order.order_no || '-';
            successTable.textContent = tableLabel;
            successCustomer.textContent = order.customer_name || document.getElementById('customerName').value.trim() || '-';
            successItemsCount.textContent = `${totalItems(items)} item`;
            successGrandTotal.textContent = `Rp ${currency(order.grand_total || 0)}`;
            successSubmittedAt.textContent = formatDateTime(order.submitted_at || new Date().toISOString());
            successBillNo.textContent = order.bill && order.bill.bill_no ? order.bill.bill_no : '-';
            renderSuccessItems(items);
            renderNextSteps(order);
            const isFinalProgress = renderTimeline(order);

            state.lastGuestToken = order.guest_token || state.lastGuestToken || null;
            persistGuestToken(state.lastGuestToken);
            orderingFlow.classList.add('hidden');
            cartBar.classList.add('hidden');
            successScreen.classList.add('show');

            clearStatusTimer();
            if (!isFinalProgress) {
                state.statusTimer = window.setInterval(() => {
                    refreshGuestOrderStatus(false);
                }, 15000);
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        async function refreshGuestOrderStatus(showToast = false) {
            if (!state.lastGuestToken) {
                return;
            }

            refreshStatusButton.disabled = true;
            refreshStatusButton.textContent = 'Memeriksa...';

            try {
                const response = await fetch(`${apiBase}/qr-menu/orders/${state.lastGuestToken}`, {
                    headers: {
                        'Accept': 'application/json',
                    },
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Status pesanan belum dapat diperiksa.');
                }

                showSuccessScreen(payload.data);

                if (showToast) {
                    showSuccess('Status pesanan berhasil diperbarui.');
                }
            } catch (error) {
                if (showToast) {
                    showError(error.message || 'Status pesanan belum dapat diperiksa.');
                }
            } finally {
                refreshStatusButton.disabled = false;
                refreshStatusButton.textContent = 'Cek Status Pesanan';
            }
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

        function setActiveOption(menuId, optionId) {
            state.activeOptionByMenu[menuId] = optionId;
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
                    fallback.innerHTML = `
                        <div class="icon">${menu.station_type === 'BAR' ? '☕' : '🍽'}</div>
                        <div class="sku">${menu.sku ?? ''}</div>
                    `;
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
                    const selectableOptions = options.filter((option) => option.is_available && option.is_active);
                    const selectedOptions = options.filter((option) => currentQty(menu.id, option.id) > 0);
                    const activeOptionId = state.activeOptionByMenu[menu.id]
                        ?? selectableOptions[0]?.id
                        ?? options[0]?.id
                        ?? null;
                    const activeOption = options.find((option) => option.id === activeOptionId) ?? null;

                    if (activeOptionId !== null && state.activeOptionByMenu[menu.id] == null) {
                        state.activeOptionByMenu[menu.id] = activeOptionId;
                    }

                    const composer = document.createElement('div');
                    composer.className = 'variant-composer';

                    const title = document.createElement('h4');
                    title.className = 'variant-title';
                    title.textContent = 'Pilih varian';
                    composer.appendChild(title);

                    const selector = document.createElement('div');
                    selector.className = 'variant-selector';

                    options.forEach((option) => {
                        const optionAvailable = option.is_available && option.is_active;
                        const chip = document.createElement('button');
                        chip.type = 'button';
                        chip.className = `variant-chip${option.id === activeOptionId ? ' active' : ''}${optionAvailable ? '' : ' unavailable'}`;
                        chip.textContent = optionAvailable ? option.name : `${option.name} Habis`;
                        if (optionAvailable) {
                            chip.addEventListener('click', () => setActiveOption(menu.id, option.id));
                        } else {
                            chip.disabled = true;
                        }
                        selector.appendChild(chip);
                    });

                    composer.appendChild(selector);

                    if (activeOption) {
                        const activeOptionAvailable = activeOption.is_available && activeOption.is_active;
                        const panel = document.createElement('div');
                        panel.className = 'variant-panel';

                        const heading = document.createElement('h4');
                        heading.textContent = activeOption.name;
                        panel.appendChild(heading);

                        const description = document.createElement('p');
                        const extraPrice = Number(activeOption.price_delta || 0);
                        description.textContent = activeOptionAvailable
                            ? (
                                extraPrice > 0
                                    ? `Tambahan Rp ${currency(extraPrice)}. Atur jumlah untuk varian ini. Pindah ke varian lain tidak akan menghapus input yang sudah dipilih.`
                                    : 'Atur jumlah untuk varian ini. Pindah ke varian lain tidak akan menghapus input yang sudah dipilih.'
                            )
                            : 'Varian ini sedang habis dan belum bisa dipesan.';
                        panel.appendChild(description);

                        if (activeOptionAvailable) {
                            const qtyPanel = document.createElement('div');
                            qtyPanel.className = 'qty-panel';

                            const qtyLabel = document.createElement('div');
                            qtyLabel.className = 'qty-label';
                            qtyLabel.textContent = 'Jumlah';
                            qtyPanel.appendChild(qtyLabel);

                            const qtyComposer = createQtyComposer(menu, activeOption, false);
                            const qtyRow = qtyComposer.firstChild;
                            if (qtyRow) {
                                qtyPanel.appendChild(qtyRow);
                            }
                            panel.appendChild(qtyPanel);

                            if (qtyComposer.children[1]) {
                                panel.appendChild(qtyComposer.children[1]);
                            }
                        }

                        composer.appendChild(panel);
                    }

                    if (selectedOptions.length > 0) {
                        const summary = document.createElement('div');
                        summary.className = 'variant-summary';
                        selectedOptions.forEach((option) => {
                            const summaryPill = document.createElement('div');
                            summaryPill.className = 'variant-summary-pill';
                            summaryPill.textContent = `${option.name} • ${currentQty(menu.id, option.id)} item`;
                            summary.appendChild(summaryPill);
                        });
                        composer.appendChild(summary);
                    }

                    body.appendChild(composer);
                } else {
                    body.appendChild(createQtyComposer(menu, null, !canOrder));
                }

                if (selectedTotal > 0) {
                    const ready = document.createElement('div');
                    ready.className = 'menu-ready';
                    ready.textContent = hasOptions
                        ? 'Pilihan menu sudah disiapkan untuk dikirim.'
                        : 'Menu sudah masuk ke draft order.';
                    body.appendChild(ready);
                }

                card.append(cover, body);
                menuContainer.appendChild(card);
            });
        }

        function refreshCart() {
            const items = Array.from(state.selected.values());
            const totalQty = items.reduce((sum, item) => sum + item.qty, 0);
            const totalPrice = items.reduce((sum, item) => sum + (item.qty * item.unitPrice), 0);

            cartBar.classList.toggle('hidden', totalQty === 0);
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
                showSuccessScreen(payload.data);
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
        refreshStatusButton.addEventListener('click', () => refreshGuestOrderStatus(true));
        backToMenuButton.addEventListener('click', showOrderingFlow);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearStatusTimer();
                return;
            }

            if (successScreen.classList.contains('show') && state.lastGuestToken) {
                refreshGuestOrderStatus(false);
            }
        });
        restoreGuestToken();
        loadMenu();
        if (state.lastGuestToken) {
            refreshGuestOrderStatus(false);
        }
    </script>
</body>
</html>

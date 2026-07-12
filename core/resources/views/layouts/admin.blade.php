<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Quản trị Bot MST' }}</title>
    @livewireStyles
    <style>
        :root {
            --bg: #f5efe3;
            --panel: rgba(255, 252, 246, 0.96);
            --panel-strong: #ffffff;
            --ink: #172033;
            --muted: #697386;
            --line: #dfd2bc;
            --accent: #0b6b61;
            --accent-strong: #094b44;
            --accent-soft: #d9f3ee;
            --danger: #b42318;
            --warn: #b54708;
            --shadow: 0 24px 64px rgba(23, 32, 51, 0.08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font-family: "IBM Plex Sans", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 222, 173, 0.45), transparent 30%),
                radial-gradient(circle at top right, rgba(164, 230, 217, 0.42), transparent 28%),
                linear-gradient(180deg, #f5efe3 0%, #fcfaf5 100%);
        }

        a {
            color: var(--accent);
            text-decoration: none;
        }

        code,
        pre {
            font-family: "IBM Plex Mono", "SFMono-Regular", monospace;
        }

        .shell {
            width: min(1320px, calc(100% - 28px));
            margin: 18px auto 42px;
        }

        .shell-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: start;
            padding: 18px 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(223, 210, 188, 0.82);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(14px);
            box-shadow: var(--shadow);
        }

        .brand-wrap {
            display: grid;
            gap: 10px;
        }

        .brand-line {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #fff7e8;
            color: var(--accent-strong);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .brand-title {
            font-size: clamp(24px, 3vw, 34px);
            line-height: 1.05;
            font-weight: 700;
            letter-spacing: -0.03em;
            margin: 0;
        }

        .brand-copy {
            margin: 0;
            max-width: 760px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .header-actions {
            display: grid;
            gap: 12px;
            justify-items: end;
        }

        .nav-pills {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }

        .nav-pill {
            display: inline-flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid transparent;
            background: rgba(239, 231, 217, 0.86);
            color: var(--ink);
            font-size: 14px;
            font-weight: 600;
        }

        .nav-pill.is-active {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 12px 24px rgba(11, 107, 97, 0.22);
        }

        .logout-form {
            display: flex;
            justify-content: flex-end;
        }

        .page-stack {
            display: grid;
            gap: 20px;
        }

        .hero-card,
        .panel {
            border: 1px solid var(--line);
            border-radius: 24px;
            background: var(--panel);
            box-shadow: var(--shadow);
        }

        .hero-card {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(280px, 1fr);
            gap: 18px;
            padding: 24px;
        }

        .eyebrow {
            display: inline-flex;
            margin-bottom: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent-strong);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero-card h1 {
            margin: 0 0 10px;
            font-size: clamp(24px, 3vw, 36px);
            line-height: 1.05;
            letter-spacing: -0.04em;
        }

        .hero-copy {
            margin: 0;
            max-width: 760px;
            color: var(--muted);
            line-height: 1.7;
        }

        .hero-aside {
            display: grid;
            gap: 12px;
        }

        .hero-stat,
        .metric-card {
            display: grid;
            gap: 8px;
            padding: 16px;
            border-radius: 18px;
            border: 1px solid rgba(223, 210, 188, 0.78);
            background: var(--panel-strong);
        }

        .hero-stat-label,
        .metric-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero-stat strong,
        .metric-card strong {
            font-size: 15px;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .grid {
            display: grid;
            gap: 20px;
        }

        .grid-default {
            grid-template-columns: minmax(320px, 380px) minmax(0, 1fr);
        }

        .grid-wide {
            grid-template-columns: minmax(0, 1.15fr) minmax(340px, 0.85fr);
        }

        .panel {
            padding: 22px;
        }

        .stack {
            display: grid;
            gap: 16px;
        }

        .stack-tight {
            gap: 10px;
        }

        h2,
        h3 {
            margin: 0;
        }

        h2 {
            font-size: 20px;
        }

        .muted {
            color: var(--muted);
        }

        .field-help {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .form-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        label {
            display: grid;
            gap: 7px;
            color: var(--muted);
            font-size: 14px;
        }

        .checkbox-line {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--ink);
            font-weight: 600;
        }

        input,
        textarea,
        select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 12px 14px;
            font: inherit;
            color: var(--ink);
            background: #fff;
        }

        textarea {
            resize: vertical;
            min-height: 92px;
        }

        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            padding: 0;
            margin: 0;
        }

        .actions,
        .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        button,
        .btn-link {
            border: 0;
            cursor: pointer;
            font: inherit;
            border-radius: 999px;
            padding: 11px 16px;
            transition: transform 120ms ease, box-shadow 120ms ease, opacity 120ms ease;
        }

        button:hover,
        .btn-link:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 14px 24px rgba(11, 107, 97, 0.18);
        }

        .btn-secondary {
            background: #efe7d9;
            color: var(--ink);
        }

        .btn-danger {
            background: #fee4e2;
            color: var(--danger);
        }

        .btn-warning {
            background: #fff0dc;
            color: var(--warn);
        }

        .status {
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid #b6eadf;
            background: var(--accent-soft);
            color: var(--accent-strong);
        }

        .error {
            color: var(--danger);
            font-size: 13px;
        }

        .metric-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .info-row {
            display: grid;
            gap: 6px;
        }

        .info-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.7);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 780px;
        }

        th,
        td {
            padding: 14px 16px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid var(--line);
        }

        th {
            background: #f9f4ea;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        td:last-child,
        th:last-child {
            padding-right: 18px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #eef2ff;
            color: #3730a3;
        }

        .pill.active {
            background: #dcfce7;
            color: #166534;
        }

        .pill.inactive {
            background: #fef3c7;
            color: #92400e;
        }

        .link-cut {
            display: inline-block;
            max-width: 340px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .payload-preview {
            margin: 0;
            max-height: 320px;
            overflow: auto;
            padding: 14px;
            border-radius: 16px;
            background: #121a2b;
            color: #f5f7fb;
            font-size: 12px;
            line-height: 1.55;
        }

        @media (max-width: 1100px) {
            .hero-card,
            .grid-default,
            .grid-wide {
                grid-template-columns: 1fr;
            }

            .header-actions {
                justify-items: stretch;
            }

            .nav-pills,
            .logout-form {
                justify-content: flex-start;
            }
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100%, calc(100% - 18px));
                margin: 12px auto 24px;
            }

            .shell-header,
            .hero-card,
            .panel {
                padding: 16px;
                border-radius: 20px;
            }

            .form-grid,
            .metric-grid {
                grid-template-columns: 1fr;
            }

            table {
                min-width: 640px;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="shell-header">
            <div class="brand-wrap">
                <div class="brand-line">
                    <span class="brand-badge">Bot MST Admin</span>
                    <h1 class="brand-title">{{ $title ?? 'Quản trị Bot MST' }}</h1>
                </div>
                <p class="brand-copy">
                    Quản lý dữ liệu Telegram, proxy xoay cho worker, và toàn bộ luồng collector từ một nơi gọn gàng hơn.
                </p>
            </div>

            <div class="header-actions">
                <nav class="nav-pills">
                    <a class="nav-pill {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}" href="{{ route('admin.dashboard') }}">
                        Telegram
                    </a>
                    <a class="nav-pill {{ request()->routeIs('admin.proxy') ? 'is-active' : '' }}" href="{{ route('admin.proxy') }}">
                        Proxy
                    </a>
                </nav>

                <form class="logout-form" method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="btn-secondary" type="submit">Đăng xuất</button>
                </form>
            </div>
        </header>

        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>

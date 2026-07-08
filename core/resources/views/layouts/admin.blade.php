<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Quản trị Telegram' }}</title>
    @livewireStyles
    <style>
        :root {
            --bg: #f7f2e8;
            --panel: #fffdf8;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #e7dcc8;
            --accent: #0f766e;
            --accent-soft: #d7f4ef;
            --danger: #b91c1c;
            --warn: #b45309;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, #fff7ed 0, transparent 32%),
                radial-gradient(circle at top right, #ecfeff 0, transparent 28%),
                linear-gradient(180deg, #f4efe6 0%, #fbfaf7 100%);
            min-height: 100vh;
        }
        a { color: var(--accent); }
        .shell {
            width: min(1180px, calc(100% - 32px));
            margin: 24px auto 40px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            padding: 18px 20px;
            background: rgba(255,255,255,.8);
            border: 1px solid rgba(231,220,200,.9);
            border-radius: 18px;
            backdrop-filter: blur(10px);
        }
        .brand {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: .02em;
        }
        .muted { color: var(--muted); }
        .grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 20px;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, .05);
        }
        h1, h2, h3 { margin: 0; }
        h2 { font-size: 20px; margin-bottom: 8px; }
        .stack { display: grid; gap: 14px; }
        label {
            display: grid;
            gap: 6px;
            font-size: 14px;
            color: var(--muted);
        }
        input, textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            border-radius: 12px;
            padding: 12px 14px;
            font: inherit;
        }
        textarea { min-height: 90px; resize: vertical; }
        .actions, .inline-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        button {
            border: 0;
            border-radius: 999px;
            padding: 10px 16px;
            font: inherit;
            cursor: pointer;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-secondary { background: #efe7d9; color: var(--ink); }
        .btn-danger { background: #fee2e2; color: var(--danger); }
        .btn-warning { background: #ffedd5; color: var(--warn); }
        .status {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--accent-soft);
            color: #115e59;
        }
        .error {
            font-size: 13px;
            color: var(--danger);
        }
        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }
        th, td {
            text-align: left;
            padding: 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }
        th {
            background: #faf6ef;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--muted);
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            background: #eef2ff;
            color: #3730a3;
        }
        .pill.active { background: #dcfce7; color: #166534; }
        .pill.inactive { background: #fef3c7; color: #92400e; }
        .link-cut {
            max-width: 320px;
            display: inline-block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        @media (max-width: 980px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="shell">
        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>

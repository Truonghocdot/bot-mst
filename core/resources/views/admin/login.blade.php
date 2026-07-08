<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập quản trị</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at top left, #cffafe 0, transparent 30%),
                radial-gradient(circle at bottom right, #fde68a 0, transparent 25%),
                linear-gradient(180deg, #faf7f2 0%, #f5efe5 100%);
            font-family: Georgia, "Times New Roman", serif;
            color: #1f2937;
        }
        .card {
            width: min(420px, calc(100% - 32px));
            padding: 28px;
            background: rgba(255,255,255,.92);
            border: 1px solid #eadfce;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, .08);
        }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { margin: 0 0 18px; color: #6b7280; }
        label { display: grid; gap: 8px; font-size: 14px; color: #6b7280; }
        input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #e5d9c6;
            font: inherit;
        }
        button {
            width: 100%;
            margin-top: 16px;
            border: 0;
            border-radius: 999px;
            padding: 14px 18px;
            background: #0f766e;
            color: #fff;
            font: inherit;
            cursor: pointer;
        }
        .error { margin-top: 10px; color: #b91c1c; font-size: 14px; }
    </style>
</head>
<body>
    <form class="card" method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <h1>Quản trị Telegram</h1>
        <p>Nhập mật khẩu để quản lý danh sách chat ID nhận thông báo.</p>

        <label>
            Mật khẩu
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        @error('password')
            <div class="error">{{ $message }}</div>
        @enderror

        <button type="submit">Đăng nhập</button>
    </form>
</body>
</html>

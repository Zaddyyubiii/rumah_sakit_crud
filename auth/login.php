<?php
session_start();

// Redirect kalau sudah login
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'dokter') {
        header("Location: ../dokter/dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'pasien') {
        header("Location: ../pasien/dashboard.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login Sistem Rumah Sakit</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #e3f2fd, #e8f5e9);
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .wrapper {
            width: 90%;
            max-width: 950px;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 18px 45px rgba(0,0,0,0.12);
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            overflow: hidden;
        }

        .left-panel {
            padding: 40px 45px;
            background: linear-gradient(135deg, #1976d2, #26a69a);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .app-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .app-subtitle {
            font-size: 15px;
            opacity: 0.92;
            margin-bottom: 25px;
        }

        .info-list {
            list-style: none;
            padding: 0;
            margin: 0 0 25px 0;
            font-size: 14px;
        }

        .info-list li {
            margin-bottom: 8px;
        }

        .badge {
            display: inline-block;
            background: rgba(255,255,255,0.18);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            margin-top: 8px;
        }

        .right-panel {
            padding: 40px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .right-panel h2 {
            margin: 0 0 18px 0;
            font-size: 22px;
            color: #333;
            text-align: left;
        }

        .right-panel p.desc {
            margin: 0 0 20px 0;
            font-size: 14px;
            color: #666;
        }

        .error {
            background: #ffebee;
            border: 1px solid #ef9a9a;
            color: #b71c1c;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 15px;
        }

        label {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
            color: #444;
        }

        input {
            width: 100%;
            padding: 11px 12px;
            margin-bottom: 14px;
            border-radius: 8px;
            border: 1px solid #cfd8dc;
            outline: none;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 2px rgba(25,118,210,0.15);
        }

        button {
            width: 100%;
            padding: 11px;
            border-radius: 999px;
            border: none;
            font-size: 15px;
            font-weight: 600;
            background: #1976d2;
            color: white;
            cursor: pointer;
            margin-top: 6px;
            transition: background 0.2s, transform 0.05s;
        }

        button:hover {
            background: #1258a3;
        }

        button:active {
            transform: scale(0.99);
        }

        .hint {
            margin-top: 14px;
            font-size: 12px;
            color: #78909c;
        }

        .hint code {
            background: #eceff1;
            padding: 2px 5px;
            border-radius: 4px;
        }

        @media (max-width: 800px) {
            .wrapper {
                grid-template-columns: 1fr;
            }
            .left-panel {
                display: none;
            }
            .right-panel {
                padding: 30px 25px;
            }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="left-panel">
        <div class="app-title">Sistem Informasi Rumah Sakit</div>
        <div class="app-subtitle">
            Kelola data pasien, tenaga medis, pemeriksaan, dan tagihan dalam satu sistem terintegrasi.
        </div>

        <ul class="info-list">
            <li>• Admin: kelola seluruh data</li>
            <li>• Dokter: akses pasien & rekam medis terkait</li>
            <li>• Pasien: melihat riwayat & tagihan</li>
        </ul>

        <div class="badge">
            Demo login: admin / dokter1 / pasien1
        </div>
    </div>

    <div class="right-panel">
        <h2>Masuk ke Akun</h2>
        <p class="desc">Gunakan username dan password yang sudah terdaftar di sistem.</p>

        <?php if (isset($_GET['error'])): ?>
            <div class="error"><?= htmlspecialchars($_GET['error']) ?></div>
        <?php endif; ?>

        <form method="POST" action="login_process.php">
            <label for="username">Username</label>
            <input id="username" type="text" name="username" placeholder="misal: admin / dokter1 / pasien1" required>

            <label for="password">Password</label>
            <input id="password" type="password" name="password" placeholder="Masukkan password" required>

            <button type="submit">Masuk</button>
        </form>

        <div class="hint">
            Untuk dummy data:<br>
            <code>admin / admin123</code><br>
            <code>dokter1 / dokter123</code><br>
            <code>pasien1 / pasien123</code>
        </div>
    </div>
</div>

</body>
</html>
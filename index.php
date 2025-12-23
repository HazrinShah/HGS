<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HGS - Hiker Guider System</title>
    <link rel="icon" type="image/png" href="img/logo.png">
    <style>
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(to right, #496eb2, #cfdef3);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .logo {
            background-color: rgba(12, 12, 82, 0.1);
            padding: 20px;
            max-width: 200px;
            margin: 60px auto 30px;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .logo img {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
        }

        .kotak {
            background-color: rgba(12, 12, 82, 0.8);
            width: 360px;
            margin: auto;
            padding: 30px 20px;
            border-radius: 15px;
            color: white;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .kotak h2 {
            margin-bottom: 5px;
        }

        .kotak h4 {
            font-style: italic;
            font-weight: normal;
            margin-bottom: 25px;
        }

        .detail {
            background-color: #1976d2;
            border: none;
            color: white;
            padding: 14px 20px;
            margin: 10px auto;
            border-radius: 25px;
            width: 200px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .detail:hover {
            background-color: #125ea8;
        }

        /* Mobile Responsive CSS */
        @media (max-width: 480px) {
            body {
                background: linear-gradient(to bottom, #496eb2, #cfdef3);
                min-height: 100vh;
            }

            .logo {
                max-width: 150px;
                margin: 40px auto 20px;
                padding: 15px;
            }

            .kotak {
                width: 90%;
                max-width: 320px;
                padding: 25px 15px;
                margin: 0 auto 30px;
            }

            .kotak h2 {
                font-size: 1.4rem;
                margin-bottom: 5px;
            }

            .kotak h4 {
                font-size: 0.95rem;
                margin-bottom: 20px;
            }

            .detail {
                width: 85%;
                max-width: 180px;
                padding: 12px 18px;
                font-size: 15px;
                margin: 8px auto;
            }
        }

        /* Small phones */
        @media (max-width: 360px) {
            .logo {
                max-width: 120px;
                margin: 30px auto 15px;
                padding: 12px;
            }

            .kotak {
                width: 92%;
                padding: 20px 12px;
            }

            .kotak h2 {
                font-size: 1.2rem;
            }

            .kotak h4 {
                font-size: 0.85rem;
            }

            .detail {
                width: 90%;
                padding: 11px 15px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

    <div class="logo">
        <img src="img/logo.png" alt="Logo">
    </div>

    <div class="kotak">
        <h2>WELCOME TO HGS</h2>
        <h4>Select Your Role</h4>

        <button class="detail" onclick="alert('You Choose Hiker'); window.location.href='hiker/HLogin.html';">HIKER</button>
        <button class="detail" onclick="alert('You Choose Guider'); window.location.href='guider/GLogin.php';">GUIDER</button>
        <button class="detail" onclick="alert('You Choose Admin'); window.location.href='admin/ALogin.php';">ADMIN</button>
    </div>

</body>
</html>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Login Notification</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            max-width: 600px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            text-align: center;
        }
        .header {
            background-color: #ff4d4d;
            color: #ffffff;
            padding: 25px;
            font-size: 26px;
            font-weight: bold;
        }
        .access-duration {
            background-color: #343a40;
            color: #ffffff;
            padding: 15px;
            font-size: 18px;
            font-weight: bold;
            text-align: center;
        }
        .content {
            padding: 25px;
            text-align: center;
            color: #333;
        }
        .details {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 16px;
            text-align: left;
        }
        .details p {
            margin: 10px 0;
            padding: 5px 0;
        }
        .access-type {
            font-weight: bold;
            color: #ffffff;
            padding: 8px 14px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 10px;
        }
        .r { background-color: #17a2b8; } /* Read Only */
        .rw { background-color: #28a745; } /* Read & Write */
        .rwed { background-color: #ffc107; } /* Read, Write, Edit, Delete */
        .full { background-color: #dc3545; } /* Admin */
        .remove-access-btn {
            display: inline-block;
            margin-top: 20px;
            background-color: #ff4d4d;
            color: white !important;
            padding: 12px 18px;
            text-decoration: none !important;
            font-size: 18px;
            border-radius: 8px;
            font-weight: bold;
            transition: background 0.3s ease-in-out;
        }
        .remove-access-btn:hover {
            background-color: #cc0000;
        }
        .footer {
            background-color: #f4f4f4;
            color: #666;
            text-align: center;
            padding: 15px;
            font-size: 14px;
        }
        .footer a {
            color: #ff4d4d;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">🚨 Employee Login Alert</div>
        
        <!-- Access Duration at the Top & Center -->
        <div class="access-duration">⏳ Access Duration: 1 Hour</div>

        <div class="content">
            <p><strong>Admin,</strong></p>
            <p>An employee has logged into the database.</p>

            <div class="details">
                <p><strong>Name:</strong> {{ $user_name }}</p>
                <p><strong>Email:</strong> {{ $user_email }}</p>
                <p><strong>Login Time:</strong> {{ $login_time }}</p>
                <p><strong>Access Type:</strong> <span class="access-type {{ $access_class }}">{{ $access_label }}</span></p>
            </div>

            <a href="https://database.medcon.ae/admin" class="remove-access-btn" style="color: white !important; text-decoration: none !important;">
                🚫 Remove Access
            </a>
        </div>

        <div class="footer">
            Need help? <a href="mailto:majd.a@medcon-me.com">Contact IT Support</a> | © 2025 Medcon Database.
        </div>
    </div>
</body>
</html>

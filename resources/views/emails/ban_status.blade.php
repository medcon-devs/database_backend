<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Ban Status Update</title>
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
            background-color: {{ $is_banned ? '#dc3545' : '#28a745' }};
            color: #ffffff;
            padding: 25px;
            font-size: 26px;
            font-weight: bold;
        }

        .ban-alert {
            background-color: {{ $is_banned ? '#343a40' : '#17a2b8' }};
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

        .ban-status {
            background-color: {{ $is_banned ? '#ffe0e0' : '#e0ffe0' }};
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 16px;
            font-weight: bold;
            color: {{ $is_banned ? '#d32f2f' : '#388e3c' }};
        }

        .admin-panel-btn {
            display: inline-block;
            margin-top: 20px;
            background-color: #007bff;
            color: white;
            padding: 12px 18px;
            text-decoration: none;
            font-size: 18px;
            border-radius: 8px;
            font-weight: bold;
            transition: background 0.3s ease-in-out;
        }

        .admin-panel-btn:hover {
            background-color: #0056b3;
        }

        .footer {
            background-color: #f4f4f4;
            color: #666;
            text-align: center;
            padding: 15px;
            font-size: 14px;
        }

        .footer a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">{{ $is_banned ? '🚫 User Banned' : '✅ User Unbanned' }}</div>
        
        <!-- Alert Message -->
        <div class="ban-alert">{{ $is_banned ? '⚠️ This User Has Been Banned' : '🎉 This User Has Been Unbanned' }}</div>

        <div class="content">
            <p><strong>Admin,</strong></p>
            <p>The following user’s access status has been updated.</p>

            <div class="details">
                <p><strong>Name:</strong> {{ $user_name }}</p>
                <p><strong>Email:</strong> {{ $user_email }}</p>
                <p><strong>Ban Status:</strong> <span class="ban-status">{{ $is_banned ? 'Banned ❌' : 'Unbanned ✅' }}</span></p>
                <p><strong>Updated At:</strong> {{ $updated_time }}</p>
            </div>

            <a href="https://database.medcon.ae/admin" class="admin-panel-btn">
                🔍 Manage Users
            </a>
        </div>

        <div class="footer">
            Need help? <a href="mailto:majd.a@medcon-me.com">Contact IT Support</a> | © 2025 Medcon Database.
        </div>
    </div>
</body>

</html>

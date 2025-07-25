<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Export Notification</title>
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
            background-color: #ff9800;
            color: #ffffff;
            padding: 25px;
            font-size: 26px;
            font-weight: bold;
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

        .export-info {
            background-color: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            color: #0d47a1;
        }

        .review-btn {
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

        .review-btn:hover {
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
            color: #ff9800;
            text-decoration: none;
            font-weight: bold;
        }
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
    </style>
</head>

<body>
    <div class="container">
        <div class="header">📤 Data Export Notification</div>

        <div class="content">
            <p><strong>Hello, Admin</strong></p>
            <p>A user has exported data from the database.</p>

            <div class="details">
                <p><strong>User Name:</strong> {{ $user_name }}</p>
                <p><strong>User Email:</strong> {{ $user_email }}</p>
                <p><strong>Export Time:</strong> {{ $export_time }}</p>
            </div>

            <div class="export-info">
                <p><strong>Records Exported:</strong> {{ $export_count }} entries</p>
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

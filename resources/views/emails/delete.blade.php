
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Update Alert</title>
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
        }

        /* .header {
            background-color: #ff9800;
            color: #ffffff;
            text-align: center;
            padding: 25px;
            font-size: 26px;
            font-weight: bold;
        } */
        .header {
            background-color: #dc3545;
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

        .old-data {
            background-color: #ffe0e0;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            color: #d32f2f;
        }

        .new-data {
            background-color: #e0ffe0;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            color: #388e3c;
        }

        .view-data-btn {
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

        .view-data-btn:hover {
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
            color: #dc3545;
            text-decoration: none;
            font-weight: bold;
        }

        .remove-access-btn {
            display: inline-block;
            margin-top: 20px;
            background-color: #ff4d4d;
            color: white;
            padding: 12px 18px;
            text-decoration: none;
            font-size: 18px;
            border-radius: 8px;
            font-weight: bold;
            transition: background 0.3s ease-in-out;
        }

        .remove-access-btn:hover {
            background-color: #cc0000;
        }
        .deletion-alert {
            background-color: #343a40;
            color: #ffffff;
            padding: 15px;
            font-size: 18px;
            font-weight: bold;
            text-align: center;
        }
        .deleted-data {
            background-color:rgb(252, 206, 201);
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            color:rgb(142, 56, 56);
        }
    </style>
</head>

<body>
    <div class="container">
    <div class="header">🚨 Data Deletion Alert</div>
        
        <!-- Deletion Alert at the Top & Center -->
        <div class="deletion-alert">⚠️ An Employee Has Deleted Data</div>
        <div class="content">
            <p><strong>Admin,</strong></p>
            <p>An employee has permanently removed data from the database.</p>

            <div class="details">
                <p><strong>Name:</strong> {{ $user_name }}</p>
                <p><strong>Email:</strong> {{ $user_email }}</p>
                <p><strong>Deletion Time:</strong> {{ $update_time }}</p>
                <!-- <p><strong>Deleted Data:</strong> {{ $data_summary }}</p> -->
                <p><strong>Restored Data:</strong></p>
                <div class="deleted-data">{{ $data_summary }}</div>

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
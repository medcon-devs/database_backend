<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New User Registration</title>
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
            background-color: #28a745;
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

        .access-type {
            font-weight: bold;
            color: #ffffff;
            padding: 8px 12px;
            border-radius: 5px;
            display: inline-block;
            margin-top: 10px;
        }

     
        .r { background-color: #17a2b8; } /* Read Only */
        .rw { background-color: #28a745; } /* Read & Write */
        .rwe { background-color:rgb(222, 196, 0); } /* Read, Write, Edit */
        .rwed { background-color: rgb(226, 105, 7); } /* Read, Write, Edit, Delete */
        .full { background-color: #dc3545; } /* Admin */
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
            color: #28a745;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">🎉 New User Registered</div>

        <div class="content">
            <p><strong>Hello, Admin</strong></p>
            <p>A new user has been successfully added to the system.</p>

            <div class="details">
                <p><strong>Name:</strong> {{ $user_name }}</p>
                <p><strong>Email:</strong> {{ $user_email }}</p>
                <p><strong>Access Type:</strong> <span class="access-type {{ $access_class }}">{{ $access_label }}</span></p>
                <p><strong>Registered At:</strong> {{ $registered_time }}</p>
            </div>

            <a href="https://database.medcon.ae/admin" class="review-btn">
                🔍 Review User Profile
            </a>
        </div>

        <div class="footer">
            Need help? <a href="mailto:majd.a@medcon-me.com">Contact IT Support</a> | © 2025 Medcon Database.
        </div>
    </div>
</body>

</html>

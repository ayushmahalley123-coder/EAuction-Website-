<?php
include 'db.php'; 

session_start();

// Check if the user is logged in and is a customer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: login.php");
    exit;
}

// Fetch the user's wallet balance
$user_id = $_SESSION['user_id'];
$balanceQuery = "SELECT wallet_balance FROM users WHERE user_id = ?";
$stmt = $conn->prepare($balanceQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($wallet_balance);
$stmt->fetch();
$stmt->close();

// Save wallet balance in the session for use in JavaScript
$_SESSION['wallet_balance'] = $wallet_balance;

// Fetch products and farmer-specified auction start time from the database
$sql = "SELECT p.listing_id, p.product AS product, u.username AS farmer_username, p.description, 
               p.starting_price, p.auction_start_time, p.pickup_location
        FROM listings p
        JOIN users u ON p.farmer_id = u.user_id
        WHERE u.role = 'farmer'";

$result = $conn->query($sql);

// Check for errors in the query
if ($conn->error) {
    echo "Error: " . $conn->error;
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Styling for the body and header */
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 0;
            color: #333;
        }
        header {
            background-color: #4CAF50;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        header h1 {
            margin: 0;
            font-size: 24px;
        }


        header a button {
            background-color: #fff;
            color: #4CAF50;
            border: 1px solid #4CAF50;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s, color 0.3s;
        }
        header a button:hover {
            background-color: #4CAF50;
            color: #fff;
        }


        .navbar-icons {
            display: flex;
            align-items: center;
        }
        .navbar-icons a {
            margin-left: 15px;
            text-decoration: none;
        }
        .navbar-icons img {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            transition: transform 0.3s;
        }
        .navbar-icons img:hover {
            transform: scale(1.1);
        }


        /* Styling for the container */
        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .container h1 {
            font-size: 28px;
            color: #4CAF50;
            margin-bottom: 20px;
            text-align: center;
        }
        .container p {
            font-size: 18px;
            color: #555;
        }

        /* Styling for the listings */
        .listings {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .listing-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }
        .listing-card:hover {
            transform: translateY(-5px);
        }
        .listing-card h3 {
            font-size: 20px;
            margin: 0 0 10px;
            color: #333;
        }
        .listing-card p {
            font-size: 16px;
            margin: 5px 0;
            color: #666;
        }
        .listing-card button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            width: 100%;
        }
        .listing-card button:hover {
            background-color: #45a049;
        }
     
    </style>
    <script>
        // Function to check if the user has enough balance
        function checkBalance(requiredAmount, userBalance) {
            if (userBalance < requiredAmount) {
                alert("Insufficient balance. Please add more funds to your wallet.");
                return false;
            }
            return true;
        }
    </script>
</head>
<body>
   <header>
        <h1>Farmers Auction Platform</h1>
        <div class="navbar-icons">
            <a href="add_money.php">
                <button>Wallet</button>
            </a>
            <a href="logout.php">
            <button>Logout</button>
        </a>
            <!-- Profile icon with link to customer profile -->
            <a href="customer_profile.php">
                <img src="profile.jpg" alt="Profile">
            </a>
        </div>
    </header>

    <div class="container">
        <h1>Browse Products</h1>
        <p>Your Wallet Balance: ₹<?= htmlspecialchars(number_format($wallet_balance, 2)) ?></p>
        <?php if ($result->num_rows > 0): ?>
            <div class="listings">
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div class="listing-card">
                        <h3><?= htmlspecialchars($row['product']) ?></h3>
                        <p><strong>Farmer:</strong> <?= htmlspecialchars($row['farmer_username']) ?></p>
                        <p><strong>Description:</strong> <?= htmlspecialchars($row['description']) ?></p>
                        <p><strong>Starting Price:</strong> ₹<?= htmlspecialchars(number_format($row['starting_price'], 2)) ?></p>
                        <p><strong>Auction Start:</strong> <?= htmlspecialchars($row['auction_start_time']) ?></p>
                        <p><strong>Pickup Location:</strong> <?= htmlspecialchars($row['pickup_location']) ?></p>
<a href="order.php?listing_id=<?= htmlspecialchars($row['listing_id']) ?>" 
                           onclick="return checkBalance(<?= htmlspecialchars($row['starting_price']) ?>, <?= $_SESSION['wallet_balance'] ?>)">
                            <button>Go For Auction</button>
                        </a>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>No listings available at the moment.</p>
        <?php endif; ?>
    </div>

   
</body>

</html>
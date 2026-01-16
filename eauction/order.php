<?php
include 'db.php';
session_start();


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata'); 

$listing_id = $_GET['listing_id'];
$customer_id = $_SESSION['user_id'];

if (!$listing_id || !$customer_id) {
    die("Missing listing ID or user ID.");
}

// Fetch product details, auction start time, and end time
$productQuery = $conn->prepare("SELECT product, starting_price, auction_start_time, auction_end_time, auction_round FROM listings WHERE listing_id = ?");
$productQuery->bind_param("i", $listing_id);
$productQuery->execute();
$productResult = $productQuery->get_result();
$product = $productResult->fetch_assoc();
$productQuery->close();

// Calculate auction duration from start and end times
$auction_start_time = strtotime($product['auction_start_time']);
$auction_end_time = strtotime($product['auction_end_time']);
$current_time = time();

$auction_duration = $auction_end_time - $auction_start_time; // Auction duration in seconds
$time_elapsed = $current_time - $auction_start_time;
$time_left = $auction_duration - $time_elapsed;
$auction_active = $time_left > 0;

if ($current_time < $auction_start_time) {
    echo "The auction hasn't started yet. Please check back at the start time: " . date("H:i:s", $auction_start_time);
    exit;
}

// Check if the auction is active or ended
if (!$auction_active) {
    // Fetch the winner for the current auction round
    $winnerQuery = $conn->prepare("SELECT users.user_id, users.username, bids.bid_amount 
                                   FROM bids 
                                   JOIN users ON bids.customer_id = users.user_id 
                                   WHERE bids.listing_id = ? AND bids.auction_round = ? 
                                   ORDER BY bids.bid_amount DESC, bids.bid_time ASC LIMIT 1");
    $winnerQuery->bind_param("ii", $listing_id, $product['auction_round']);
    $winnerQuery->execute();
    $winnerResult = $winnerQuery->get_result();
    $winner = $winnerResult->fetch_assoc();
    $winnerQuery->close();

    if ($winner) {
        // Insert a new order record
        $createOrder = $conn->prepare("INSERT INTO orders (customer_id, listing_id, bid_amount, order_date) VALUES (?, ?, ?, NOW())");
        $createOrder->bind_param("iid", $winner['user_id'], $listing_id, $winner['bid_amount']);
        $createOrder->execute();
        
        // Get the new order_id
        $order_id = $conn->insert_id;
        $createOrder->close();
    }

    // Archive bids before clearing them from the active bids table
    if ($winner) {
        $archiveBidsQuery = $conn->prepare("INSERT INTO archived_bids (customer_id, listing_id, bid_amount, bid_time, auction_round) 
                                             SELECT customer_id, listing_id, bid_amount, bid_time, auction_round 
                                             FROM bids WHERE listing_id = ? AND auction_round = ?");
        $archiveBidsQuery->bind_param("ii", $listing_id, $product['auction_round']);
        $archiveBidsQuery->execute();
        $archiveBidsQuery->close();
    }

    // Clear bids from the current round in the `bids` table
    $clearBidsQuery = $conn->prepare("DELETE FROM bids WHERE listing_id = ? AND auction_round = ?");
    $clearBidsQuery->bind_param("ii", $listing_id, $product['auction_round']);
    $clearBidsQuery->execute();
    $clearBidsQuery->close();

    // Increment auction round in listings without changing auction start time
    $updateRoundQuery = $conn->prepare("UPDATE listings SET auction_round = auction_round + 1 WHERE listing_id = ?");
    $updateRoundQuery->bind_param("i", $listing_id);
    $updateRoundQuery->execute();
    $updateRoundQuery->close();
}

// Retrieve bids for the current auction round
$bidsQuery = $conn->prepare("SELECT users.username, bids.bid_amount, bids.bid_time 
                             FROM bids 
                             JOIN users ON bids.customer_id = users.user_id 
                             WHERE bids.listing_id = ? AND bids.auction_round = ? 
                             ORDER BY bids.bid_amount DESC, bids.bid_time ASC");
$bidsQuery->bind_param("ii", $listing_id, $product['auction_round']);
$bidsQuery->execute();
$allBids = $bidsQuery->get_result();
$bidsQuery->close();

// Handle a new bid if auction is still active
if ($_SERVER["REQUEST_METHOD"] == "POST" && $auction_active) {
    $bid_amount = $_POST['bid_amount'];

    // Fetch customer’s wallet balance
    $balanceQuery = $conn->prepare("SELECT wallet_balance FROM users WHERE user_id = ?");
    $balanceQuery->bind_param("i", $customer_id);
    $balanceQuery->execute();
    $balanceQuery->bind_result($wallet_balance);
    $balanceQuery->fetch();
    $balanceQuery->close();

    if ($bid_amount > $wallet_balance) {
        echo "<script>alert('Insufficient balance for this bid.');</script>";
    } else {
        // Insert the new bid for the current round
        $stmt = $conn->prepare("INSERT INTO bids (customer_id, listing_id, bid_amount, bid_time, auction_round) VALUES (?, ?, ?, NOW(), ?)");
        $stmt->bind_param("iisi", $customer_id, $listing_id, $bid_amount, $product['auction_round']);

        if ($stmt->execute()) {
            echo "<script>alert('Your bid has been placed.');</script>";
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Bid</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
        }

        .navbar {
            width: 100%;
            background-color: #4CAF50;
            padding: 15px;
            box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: flex-end;
        }

        .navlist a {
            color: #4CAF50;
            background-color: white;
            font-size: 1rem;
            margin-right: 15px;
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 25px;
            transition: transform 0.3s, background-color 0.3s;
        }

        .navlist a:hover {
            background-color: darkgreen;
            color: white;
            transform: scale(1.1);
        }

        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.3);
        }

        h1, h2 {
            color: #4CAF50;
            margin-bottom: 10px;
        }

        #timer {
            font-size: 1.5em;
            color: #ff5722;
            margin: 15px 0;
        }

        form {
            margin: 20px 0;
        }

        form input[type="number"],
        form button {
            padding: 10px;
            font-size: 1em;
            margin-top: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            outline: none;
            transition: box-shadow 0.3s, border-color 0.3s;
        }

        form input[type="number"]:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.5);
        }

        form button {
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
        }

        form button:hover {
            background-color: #45a049;
            transform: scale(1.05);
        }

        .bids-list h3 {
            color: #4CAF50;
            margin-top: 20px;
        }

        .bid-entry {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
            font-size: 1em;
        }

        .bid-entry span {
            min-width: 80px;
            text-align: right;
        }

        .bid-entry span:first-child {
            flex-grow: 1;
            text-align: left;
        }

        .bid-entry .price {
            color: #4CAF50;
            font-weight: bold;
        }

        .winner {
            margin-top: 15px;
            padding: 15px;
            background-color: #e0f7fa;
            color: #00796b;
            font-weight: bold;
            border-radius: 8px;
            text-align: center;
        }

        .payment-button {
            display: inline-block;
            background-color:#4CAF50;
            color: white;
            font-size: 0.9em;
            padding: 8px 20px;
            border-radius: 20px;
            text-align: center;
            text-decoration: none;
            transition: background-color 0.3s ease, transform 0.2s ease;
            margin-top: 10px;
        }

        .payment-button:hover {
            background-color: #5a6268;
            transform: scale(1.05);
        }
    </style>
</head>
<body>

    <div class="navbar">
        <div class="navlist">
            <a href="customer_dashboard.php">Home</a>
        </div>
    </div>

    <div class="container">
        <h1>Place Your Bid</h1>
        <h2>Product: <?php echo htmlspecialchars($product['product']); ?></h2>
        <p>Starting Price: ₹<?php echo number_format($product['starting_price'], 2); ?></p>
        <div id="timer"></div>

        <?php if (!$auction_active && isset($winner)): ?>
          
            <div class="winner-message">
                    <h3>Congratulations, <?= htmlspecialchars($winner['username'] ?? '') ?>! You won the auction with a bid of ₹<?= htmlspecialchars(number_format($winner['bid_amount'] ?? 0, 2)) ?></h3>
                     <form action="payment_page.php" method="GET">
            <input type="hidden" name="order_id" value="<?= htmlspecialchars($order_id) ?>">
            <input type="hidden" name="listing_id" value="<?= htmlspecialchars($listing_id) ?>">
            <input type="hidden" name="winner_id" value="<?= htmlspecialchars($winner['user_id'] ?? '') ?>">
            <button type="submit">Confirm Order</button>
        </form>
                </div>
        <?php else: ?>
            <form method="POST">
                <label for="bid_amount">Enter Your Bid Amount (₹):</label><br>
                <input type="number" id="bid_amount" name="bid_amount" min="<?php echo $product['starting_price']; ?>" step="any" required>
                <button type="submit">Place Bid</button>
            </form>
        <?php endif; ?>

        <div class="bids-list">
            <h3>Current Bids:</h3>
            <?php while ($bid = $allBids->fetch_assoc()) : ?>
                <div class="bid-entry">
                    <span><?php echo htmlspecialchars($bid['username']); ?></span>
                    <span class="price">₹<?php echo number_format($bid['bid_amount'], 2); ?></span>
                    <span><?php echo date("H:i:s", strtotime($bid['bid_time'])); ?></span>
                </div>
            <?php endwhile; ?>

           
        </div>
    </div>

    <script>
        const auctionEndTime = <?php echo $auction_end_time * 1000; ?>;
        const currentTime = <?php echo $current_time * 1000; ?>;
        let timeLeftInMs = auctionEndTime - currentTime;

        function updateTimer() {
            if (timeLeftInMs <= 0) {
                document.getElementById('timer').textContent = "Auction Ended";
                clearInterval(timerInterval);
                return;
            }
            const days = Math.floor(timeLeftInMs / (1000 * 60 * 60 * 24));
            const hours = Math.floor((timeLeftInMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((timeLeftInMs % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((timeLeftInMs % (1000 * 60)) / 1000);
            document.getElementById('timer').textContent = `Time Left: ${days}d ${hours}h ${minutes}m ${seconds}s`;
            timeLeftInMs -= 1000;
        }

        const timerInterval = setInterval(updateTimer, 1000);
        updateTimer();
    </script>
</body>
</html>

<?php
include 'config.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Display All Records</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 20px;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 25px;
        }
        .container {
            width: 95%; /* Increased width for more columns */
            max-width: 1400px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            overflow-x: auto; /* Allows horizontal scrolling for many columns */
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-size: 13px; /* Reduced font size to fit more columns */
            white-space: nowrap; /* Prevents long password hash from wrapping */
        }
        th {
            background-color: #f2f2f2;
            color: #555;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .add-link {
            display: block;
            text-align: center;
            margin-bottom: 20px;
            font-size: 16px;
            font-weight: bold;
        }
        .actions a {
            padding: 4px 6px;
            border-radius: 3px;
            margin: 0 2px;
            font-size: 12px;
            text-decoration: none;
        }
        .actions a:first-child {
            background-color: #007bff;
            color: white;
        }
        .actions a:last-child {
            background-color: #dc3545;
            color: white;
        }
        .password-hash {
            font-family: monospace;
            font-size: 10px; /* Smallest font size for the long hash */
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .no-results {
            text-align: center;
            padding: 20px;
            color: #888;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>User Records</h1>

    <div class="add-link">
        <a href='create.php'>+ Add a New User</a>
    </div>

    <?php
    // CRITICAL CHANGE: We now select the 'password' column.
    $sql = "SELECT id, role_id, name, email, age, city_address, birthdate, role, created_at, password FROM users ORDER BY created_at DESC"; 
    $result = mysqli_query($conn, $sql);

    if (mysqli_num_rows($result) > 0) {
        echo "<table>";
        echo "<thead><tr>
                <th>ID</th>
                <th>Role ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Age</th>
                <th>City Address</th>
                <th>Birthdate</th>
                <th>Role</th>
                <th>Date Created</th>
                <th>Hashed Password</th> <th>Actions</th>
              </tr></thead><tbody>";

        // Output data of each row
        while($row = mysqli_fetch_assoc($result)) { 
            echo "<tr>";
            echo "<td>" . $row["id"]. "</td>";
            echo "<td>" . $row["role_id"]. "</td>";
            echo "<td>" . $row["name"]. "</td>";
            echo "<td>" . $row["email"]. "</td>";
            echo "<td>" . $row["age"]. "</td>";
            echo "<td>" . $row["city_address"]. "</td>";
            echo "<td>" . $row["birthdate"]. "</td>";
            echo "<td>" . $row["role"]. "</td>";
            echo "<td>" . $row["created_at"]. "</td>";
            // NEW COLUMN DISPLAY: Displaying the password hash
            echo "<td class='password-hash' title='" . htmlspecialchars($row["password"]) . "'>" . $row["password"]. "</td>"; 
            echo "<td class='actions'>";
            // Links for Update and Delete
            echo "<a href='update.php?id=" . $row["id"] . "'>Edit</a>";
            echo "<a href='delete.php?id=" . $row["id"] . "' onclick=\"return confirm('Are you sure you want to delete this record?');\">Delete</a>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<p class='no-results'>0 results found in the database. <a href='create.php'>Add one now!</a></p>";
    }

    mysqli_close($conn);
    ?>
</div>

</body>
</html>
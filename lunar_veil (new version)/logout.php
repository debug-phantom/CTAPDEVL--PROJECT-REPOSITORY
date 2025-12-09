<?php
// 1. Start the session so the script can access the session variables.
session_start();

// 2. Unset all session variables associated with the current session.
// This is important to clear the login status.
$_SESSION = array(); // Clear the $_SESSION array completely (or use session_unset())

// 3. Destroy the session itself.
// This removes the session data from the server's storage (e.g., the temp directory).
session_destroy();

// 4. Redirect the user back to the login page.
// The user is now logged out and must re-authenticate to view index.php.
header("Location: login.html");
exit; // Always call exit after a header redirect
?>
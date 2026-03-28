<?php
session_start();
include 'db.php'; // Include your database connection file

// Fetch all categories
$categories = [];
$stmt = $conn->prepare("SELECT id, name FROM categories ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $categories[$row['id']] = [
        'name' => $row['name'],
        'assets' => [] // Initialize an empty array for assets within each category
    ];
}
$stmt->close();

// Fetch all assets and associate them with their respective categories
$stmt = $conn->prepare("SELECT id, name, category_id FROM assets ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // If the category exists for the current asset, add the asset to it
    if (isset($categories[$row['category_id']])) {
        $categories[$row['category_id']]['assets'][] = $row;
    }
}
$stmt->close();

// Generate the HTML for the tree view
echo '<ul>';
foreach ($categories as $categoryId => $category) {
    echo '<li>';
    // Determine the classes for the category span based on whether it has assets
    $categorySpanClasses = 'category-name-display'; // A new base class for category names
    if (!empty($category['assets'])) {
        // Only add 'category-item' and 'expandable' if there are assets,
        // so the + sign and click behavior apply only then.
        $categorySpanClasses .= ' category-item expandable';
    }

    echo '<span class="' . $categorySpanClasses . '">' . htmlspecialchars($category['name']) . '</span>'; // Display category name
    if (!empty($category['assets'])) { // If there are assets in this category, create a nested list
        echo '<ul class="asset-list">'; // By default, this list will be hidden via CSS (display: none)
        foreach ($category['assets'] as $asset) {
            echo '<li class="asset-item" data-id="' . htmlspecialchars($asset['id']) . '">' . htmlspecialchars($asset['name']) . '</li>';
        }
        echo '</ul>';
    }
    echo '</li>';
}
echo '</ul>';
?>
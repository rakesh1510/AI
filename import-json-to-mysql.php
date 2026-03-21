<?php
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

function read_json_local($path, $default){
    if(!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    // Categories
    $toolsData = read_json_local(__DIR__ . '/data/tools.json', ['categories'=>[], 'tools'=>[]]);
    $categoryNames = $toolsData['categories'] ?? [];
    foreach(($toolsData['tools'] ?? []) as $tool){
        $cat = trim((string)($tool['category'] ?? ''));
        if($cat !== '' && !in_array($cat, $categoryNames, true)){
            $categoryNames[] = $cat;
        }
    }
    $categoryNames = array_values(array_unique(array_filter($categoryNames)));

    $catStmt = $pdo->prepare("INSERT INTO categories (slug, name, description) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE name = VALUES(name)");
    foreach($categoryNames as $name){
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        if($slug === '') continue;
        $catStmt->execute([$slug, $name]);
    }

    $catMap = [];
    foreach($pdo->query("SELECT id, name FROM categories")->fetchAll() as $row){
        $catMap[(string)$row['name']] = (int)$row['id'];
    }

    // Tools
    $toolStmt = $pdo->prepare("INSERT INTO tools 
        (slug, name, category_id, short_description, description, features, pros, cons, use_cases, pricing, website, affiliate, added, tags, logo, pricing_type, seo_title, seo_description, featured_level, featured_until, status)
        VALUES
        (:slug, :name, :category_id, :short_description, :description, :features, :pros, :cons, :use_cases, :pricing, :website, :affiliate, :added, :tags, :logo, :pricing_type, :seo_title, :seo_description, :featured_level, :featured_until, 'published')
        ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        category_id = VALUES(category_id),
        short_description = VALUES(short_description),
        description = VALUES(description),
        features = VALUES(features),
        pros = VALUES(pros),
        cons = VALUES(cons),
        use_cases = VALUES(use_cases),
        pricing = VALUES(pricing),
        website = VALUES(website),
        affiliate = VALUES(affiliate),
        added = VALUES(added),
        tags = VALUES(tags),
        logo = VALUES(logo),
        pricing_type = VALUES(pricing_type),
        seo_title = VALUES(seo_title),
        seo_description = VALUES(seo_description),
        featured_level = VALUES(featured_level),
        featured_until = VALUES(featured_until)");

    foreach(($toolsData['tools'] ?? []) as $tool){
        $categoryName = (string)($tool['category'] ?? '');
        $categoryId = $catMap[$categoryName] ?? null;
        $featuredUntil = trim((string)($tool['featured_until'] ?? ''));
        $toolStmt->execute([
            ':slug' => (string)($tool['id'] ?? ''),
            ':name' => (string)($tool['name'] ?? ''),
            ':category_id' => $categoryId,
            ':short_description' => (string)($tool['short'] ?? ''),
            ':description' => (string)($tool['description'] ?? ''),
            ':features' => json_encode($tool['features'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':pros' => json_encode($tool['pros'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':cons' => json_encode($tool['cons'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':use_cases' => json_encode($tool['use_cases'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':pricing' => (string)($tool['pricing'] ?? ''),
            ':website' => (string)($tool['website'] ?? ''),
            ':affiliate' => (string)($tool['affiliate'] ?? ''),
            ':added' => (string)($tool['added'] ?? ''),
            ':tags' => json_encode($tool['tags'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':logo' => (string)($tool['logo'] ?? ''),
            ':pricing_type' => (string)($tool['pricing_type'] ?? ''),
            ':seo_title' => (string)($tool['seo_title'] ?? ''),
            ':seo_description' => (string)($tool['seo_description'] ?? ''),
            ':featured_level' => (int)($tool['featured_level'] ?? 0),
            ':featured_until' => ($featuredUntil !== '' ? $featuredUntil : null),
        ]);
    }

    // Posts
    $posts = read_json_local(__DIR__ . '/data/posts.json', []);
    $postStmt = $pdo->prepare("INSERT INTO posts (slug, title, excerpt, content, published_at, status)
        VALUES (:slug, :title, :excerpt, :content, :published_at, 'published')
        ON DUPLICATE KEY UPDATE title = VALUES(title), excerpt = VALUES(excerpt), content = VALUES(content), published_at = VALUES(published_at)");
    foreach($posts as $post){
        $postStmt->execute([
            ':slug' => (string)($post['slug'] ?? ''),
            ':title' => (string)($post['title'] ?? ''),
            ':excerpt' => (string)($post['excerpt'] ?? ''),
            ':content' => (string)($post['content'] ?? ''),
            ':published_at' => (string)($post['date'] ?? null),
        ]);
    }

    // Use cases
    $cases = read_json_local(__DIR__ . '/data/use_cases.json', []);
    $caseStmt = $pdo->prepare("INSERT INTO use_cases (slug, label, keywords_json, fallback_json)
        VALUES (:slug, :label, :keywords_json, :fallback_json)
        ON DUPLICATE KEY UPDATE label = VALUES(label), keywords_json = VALUES(keywords_json), fallback_json = VALUES(fallback_json)");
    foreach($cases as $slug => $case){
        $caseStmt->execute([
            ':slug' => (string)$slug,
            ':label' => (string)($case['label'] ?? $slug),
            ':keywords_json' => json_encode($case['keywords'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':fallback_json' => json_encode($case['fallback'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
    }

    // Site settings
    $site = read_json_local(__DIR__ . '/data/site.json', []);
    $siteStmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach($site as $key => $value){
        $isScalar = is_scalar($value) || $value === null;
        $siteStmt->execute([(string)$key, $isScalar ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    $pdo->commit();
    echo "Import completed successfully.\n";
    echo "Imported tools: " . count($toolsData['tools'] ?? []) . "\n";
    echo "Imported categories: " . count($categoryNames) . "\n";
    echo "Imported posts: " . count($posts) . "\n";
    echo "Imported use cases: " . count($cases) . "\n";
} catch (Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Import failed: " . $e->getMessage() . "\n";
}

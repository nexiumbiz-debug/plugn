from pathlib import Path
import re

controller = Path("backend/controllers/RestaurantController.php")
source = controller.read_text(encoding="utf-8")

match = re.search(
    r"public function actionUpdateSitemap\(.*?\n    /\*\*",
    source,
    re.S,
)

if not match:
    raise SystemExit("Could not locate actionUpdateSitemap block")

block = match.group(0)

for forbidden in [
    'or die("Unable to open file!")',
    " or die(",
    "print_r(Yii::$app->request->queryParams)",
    "mkdir($dirName, 0777, true)",
    "mkdir($storeDir, 0777, true)",
]:
    if forbidden in block:
        raise SystemExit(f"Forbidden sitemap debug/hard-stop pattern remains: {forbidden}")

required_patterns = [
    r"mkdir\(\$dirName,\s*0755,\s*true\)",
    r"mkdir\(\$storeDir,\s*0755,\s*true\)",
    r"\$sitemap\s*=\s*fopen\(\$sitemapPath,\s*\"w\"\)",
    r"if\s*\(\$sitemap\s*===\s*false\)",
    r"fwrite\(\$sitemap,.*?\)\s*===\s*false",
    r"file_get_contents\(\$sitemapPath\)",
    r"file_get_contents\(\$sitemapPath\);\s*if\s*\(\$fileToBeUploaded\s*===\s*false\)",
    r"Yii::error\('\[Sitemap > File open failed\]",
    r"Yii::error\('\[Sitemap > File write failed\]",
    r"Yii::error\('\[Sitemap > File read failed\]",
    r"setFlash\('errorResponse'",
    r"return \$this->redirect\(\['view', 'id' => \$store->restaurant_uuid\]\)",
]

for pattern in required_patterns:
    if not re.search(pattern, block, re.S):
        raise SystemExit(f"Missing expected sitemap guard pattern: {pattern}")

print("PASS backend sitemap update handles temp file failures without die")

param()
$ErrorActionPreference = 'Stop'
$taskRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$taskDist = Join-Path $taskRoot 'dist'
$taskArchive = Join-Path $taskDist 'lap-crash-analysis.zip'
if (-not (Test-Path -LiteralPath (Join-Path $taskRoot 'vendor/autoload.php'))) {
    throw 'Run composer install before creating a deployment package.'
}
New-Item -ItemType Directory -Path $taskDist -Force | Out-Null
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskStream = [System.IO.File]::Open($taskArchive, [System.IO.FileMode]::Create)
$taskZip = New-Object System.IO.Compression.ZipArchive($taskStream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $taskFiles = @()
    foreach ($taskDirectory in @('app','bin','database','public','templates','vendor','docs')) {
        $taskFiles += Get-ChildItem -LiteralPath (Join-Path $taskRoot $taskDirectory) -File -Recurse -Force
    }
    foreach ($taskFilename in @('composer.json','composer.lock','.env.example','README.md','storage/.gitkeep')) {
        $taskFiles += Get-Item -LiteralPath (Join-Path $taskRoot $taskFilename) -Force
    }
    foreach ($taskFile in $taskFiles) {
        $taskEntry = $taskFile.FullName.Substring($taskRoot.Length + 1).Replace('\','/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($taskZip, $taskFile.FullName, $taskEntry, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally {
    $taskZip.Dispose()
    $taskStream.Dispose()
}
$taskRead = [System.IO.Compression.ZipFile]::OpenRead($taskArchive)
try {
    $taskForbidden = $taskRead.Entries | Where-Object { $_.FullName -eq '.env' -or $_.FullName -match '^test-results/|^tests/|\.sqlite($|-)|^storage/(?!\.gitkeep$)' }
    if ($taskForbidden) { throw 'Deployment package unexpectedly contains private runtime or test files.' }
    Write-Output ('Created ' + $taskArchive)
    Write-Output ($taskRead.Entries.Count.ToString() + ' files; no local configuration, demo databases, or runtime logs.')
} finally { $taskRead.Dispose() }

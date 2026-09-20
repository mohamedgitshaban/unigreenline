<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ucfirst($currentPage) }} — VetPharma ERP API Docs</title>
    <style>
        :root {
            --bg: #f7f7f8;
            --panel: #ffffff;
            --border: #e3e3e6;
            --text: #1f2328;
            --text-muted: #6b7280;
            --link: #2563eb;
            --code-bg: #f0f1f3;
            --sidebar-width: 260px;
            --get: #0969da;
            --post: #1a7f37;
            --put: #9a6700;
            --patch: #9a6700;
            --delete: #cf222e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            color: var(--text);
            background: var(--bg);
            display: flex;
            min-height: 100vh;
        }
        nav.sidebar {
            width: var(--sidebar-width);
            flex-shrink: 0;
            background: var(--panel);
            border-right: 1px solid var(--border);
            padding: 24px 16px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        nav.sidebar h1 {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 4px;
            padding: 0 8px;
        }
        nav.sidebar p.subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0 0 20px;
            padding: 0 8px;
        }
        nav.sidebar a {
            display: block;
            padding: 7px 8px;
            border-radius: 6px;
            color: var(--text);
            text-decoration: none;
            font-size: 13.5px;
            margin-bottom: 2px;
        }
        nav.sidebar a:hover { background: var(--code-bg); }
        nav.sidebar a.active { background: #dbeafe; color: #1d4ed8; font-weight: 600; }
        main {
            flex: 1;
            min-width: 0;
            padding: 40px 48px 120px;
            max-width: 900px;
        }
        main h1 { font-size: 26px; border-bottom: 1px solid var(--border); padding-bottom: 12px; }
        main h2 {
            font-size: 19px;
            margin-top: 40px;
            padding-top: 8px;
            border-top: 1px solid var(--border);
        }
        main h3 { font-size: 15px; margin-top: 28px; color: var(--text); }
        main p, main li { line-height: 1.65; font-size: 14.5px; }
        main a { color: var(--link); }
        main table {
            border-collapse: collapse;
            width: 100%;
            margin: 16px 0;
            font-size: 13.5px;
        }
        main th, main td {
            border: 1px solid var(--border);
            padding: 6px 10px;
            text-align: left;
        }
        main th { background: var(--code-bg); }
        main code {
            background: var(--code-bg);
            padding: 2px 5px;
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
        }
        main pre {
            background: #0d1117;
            color: #e6edf3;
            padding: 14px 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.55;
        }
        main pre code { background: none; padding: 0; color: inherit; }
        main blockquote {
            margin: 16px 0;
            padding: 4px 16px;
            border-left: 3px solid #f0b429;
            background: #fffbeb;
            color: #6b5900;
        }
        main hr { border: none; border-top: 1px solid var(--border); margin: 32px 0; }
        .method-badge {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 11.5px;
            color: #fff;
            margin-right: 6px;
        }
        .method-get { background: var(--get); }
        .method-post { background: var(--post); }
        .method-put, .method-patch { background: var(--put); }
        .method-delete { background: var(--delete); }
        .error-ref {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px 20px;
            margin: 20px 0;
        }
        @media (max-width: 800px) {
            body { flex-direction: column; }
            nav.sidebar { width: 100%; height: auto; position: static; border-right: none; border-bottom: 1px solid var(--border); }
            main { padding: 24px; max-width: 100%; }
        }
    </style>
</head>
<body>
    <nav class="sidebar">
        <h1>VetPharma ERP</h1>
        <p class="subtitle">API Reference</p>
        @foreach ($pages as $slug => $file)
            <a href="{{ route('docs.show', ['page' => $slug]) }}" class="{{ $slug === $currentPage ? 'active' : '' }}">
                {{ $slug === 'overview' ? 'Overview' : ucfirst($slug) }}
            </a>
        @endforeach
    </nav>
    <main>
        <div class="error-ref">
            <strong>Standard error shapes</strong> (every endpoint below, unless noted otherwise):
            <code>401</code> <code>{"message": "Unauthenticated."}</code> for a missing/invalid token,
            <code>403</code> <code>{"message": "This action is unauthorized."}</code> for a valid token
            lacking the required permission, and <code>422</code>
            <code>{"message": "...", "errors": {"field": ["..."]}}</code> for validation failures.
            Endpoint-specific error bodies (insufficient stock, credit limit, invalid state
            transitions, etc.) are documented inline below where they differ from this.
        </div>
        {!! $contentHtml !!}
    </main>
</body>
</html>

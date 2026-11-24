<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>الواجهة البرمجية · المستورد</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">

    <style>
        /*
         * Swagger UI مبنيّ للإنجليزية من اليسار إلى اليمين، ووصف هذه
         * الواجهة عربيّ. فالصفحة ترتدّ إلى اليمين، والشرح يُقرأ عربياً،
         * بينما تبقى المسارات والشيفرة والأمثلة في اتجاهها اللاتيني —
         * الخلط بينهما هو ما يجعل الوثيقة غير مقروءة.
         */
        :root {
            --navy: #142d4e;
            --teal: #16a6a1;
            --ink: #0f1f35;
            --line: #e2e8f0;
            --font: "IBM Plex Sans Arabic", "Segoe UI", system-ui, sans-serif;
            --mono: "IBM Plex Mono", ui-monospace, SFMono-Regular, monospace;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f5f7fa;
            font-family: var(--font);
        }

        /* ——— ترويسة الهوية ——— */
        .masthead {
            background: var(--navy);
            color: #fff;
            padding: 1.5rem max(16px, env(safe-area-inset-right, 0px)) 1.6rem max(16px, env(safe-area-inset-left, 0px));
        }

        .masthead-inner { max-width: 1460px; margin: 0 auto; }

        .mark {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            font-weight: 700;
            font-size: 0.9rem;
            letter-spacing: 0.02em;
        }

        .mark i {
            width: 0.6rem;
            height: 0.6rem;
            border-radius: 999px;
            background: var(--teal);
        }

        .masthead h1 {
            margin: 0.7rem 0 0;
            font-size: clamp(1.4rem, 4vw, 2rem);
            font-weight: 700;
        }

        .masthead p {
            margin: 0.45rem 0 0;
            max-width: 62ch;
            color: #c8d6e8;
            font-size: 0.95rem;
            line-height: 1.75;
        }

        .masthead nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1.1rem;
        }

        .masthead nav a {
            font-size: 0.82rem;
            color: #fff;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 999px;
            padding: 0.3rem 0.85rem;
        }

        .masthead nav a:hover { background: rgba(255, 255, 255, 0.12); }
        .masthead nav a:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }

        /* ——— الوثيقة ——— */
        #swagger { max-width: 1460px; margin: 0 auto; }

        .swagger-ui { font-family: var(--font); }

        /* الصفحة إلى اليمين */
        .swagger-ui .wrapper,
        .swagger-ui .opblock-summary,
        .swagger-ui .opblock-tag,
        .swagger-ui .scheme-container .schemes { direction: rtl; }

        /* وما كان لاتينياً يبقى لاتينياً: المسار، والرمز، والمثال */
        .swagger-ui .opblock-summary-method,
        .swagger-ui .opblock-summary-path,
        .swagger-ui .opblock-summary-path__deprecated,
        .swagger-ui .parameter__name,
        .swagger-ui .parameter__type,
        .swagger-ui .prop-type,
        .swagger-ui .model,
        .swagger-ui .model-title,
        .swagger-ui table.model tbody tr td:first-of-type,
        .swagger-ui .microlight,
        .swagger-ui .response-col_status,
        .swagger-ui code,
        .swagger-ui pre,
        .swagger-ui input,
        .swagger-ui textarea,
        .swagger-ui select {
            direction: ltr;
            unicode-bidi: isolate;
            text-align: left;
            font-family: var(--mono);
        }

        /* والشرح عربيّ: وصف العملية، ووصف الحقل، ووصف الاستجابة */
        .swagger-ui .markdown,
        .swagger-ui .renderedMarkdown,
        .swagger-ui .opblock-description-wrapper,
        .swagger-ui .opblock-summary-description,
        .swagger-ui .opblock-tag small,
        .swagger-ui .response-col_description,
        .swagger-ui .parameter__in,
        .swagger-ui .info .description,
        .swagger-ui .info .title {
            direction: rtl;
            text-align: right;
            font-family: var(--font);
            line-height: 1.85;
        }

        /* وصفٌ عربيّ داخل خليةٍ لاتينية الاتجاه يحتاج عزلاً صريحاً */
        .swagger-ui table tbody tr td .renderedMarkdown p,
        .swagger-ui .parameter__name + .renderedMarkdown { unicode-bidi: isolate; }

        .swagger-ui .markdown code,
        .swagger-ui .renderedMarkdown code {
            direction: ltr;
            unicode-bidi: isolate;
            font-family: var(--mono);
            background: #eef2f7;
            border-radius: 5px;
            padding: 0.1em 0.35em;
        }

        /*
         * فجوةٌ بين الأعمدة. خلايا Swagger UI بلا حشوٍ أفقيّ، فالوصف
         * العربيّ — وهو يُحاذي يمين خليّته — يلتصق باسم الحقل اللاتينيّ
         * في الخليّة المجاورة فيُقرآن كلمةً واحدة.
         */
        /* ترويسات Swagger UI خلايا td داخل thead، فالقاعدة تشمل الاثنين */
        .swagger-ui table tr > td + td,
        .swagger-ui table tr > th + th { padding-inline-start: 1.25rem; }

        /* جداول الوصف في الـ info تُقرأ عربياً */
        .swagger-ui .info .description table { direction: rtl; }
        .swagger-ui .info .description th,
        .swagger-ui .info .description td { text-align: right; padding: 0.35rem 0.6rem; }

        .swagger-ui .info .description blockquote {
            border-inline-start: 3px solid var(--teal);
            border-inline-end: 0;
            background: #e6f6f5;
            margin-inline: 0;
            padding: 0.6rem 0.9rem;
            border-radius: 0 10px 10px 0;
        }

        /* الهوية: الفيروزيّ بدل الأخضر، والكحليّ بدل الأسود */
        .swagger-ui .btn.authorize,
        .swagger-ui .btn.authorize svg { color: var(--teal); fill: var(--teal); }
        .swagger-ui .btn.authorize { border-color: var(--teal); }
        .swagger-ui .opblock-tag { color: var(--navy); border-bottom-color: var(--line); }
        .swagger-ui .info .title { color: var(--navy); }
        .swagger-ui .topbar { display: none; }

        @media (max-width: 640px) {
            .swagger-ui .opblock-summary { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <header class="masthead">
        <div class="masthead-inner">
            <div class="mark"><i></i> المستورد · ALMUSTAWRED</div>
            <h1>الواجهة البرمجية — v1</h1>
            <p>
                كلّ مسارٍ في المنصة: ماذا يفعل، ومن يستدعيه، وماذا يأخذ، وماذا يعيد،
                ومتى يرفض — وتجريبه من هذه الصفحة بعد الضغط على Authorize.
            </p>
            <nav>
                <a href="{{ url('api/docs/openapi.json') }}">مواصفة OpenAPI (JSON)</a>
                <a href="{{ url('/') }}">المنصة</a>
            </nav>
        </div>
    </header>

    <div id="swagger"></div>

    <script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: @json(url('api/docs/openapi.json')),
            dom_id: '#swagger',
            deepLinking: true,
            /* مجموعاً بالوسم ومطويّاً: ستة عشر باباً لا تُقرأ مفتوحةً كلّها */
            docExpansion: 'none',
            tagsSorter: 'none',
            operationsSorter: 'none',
            defaultModelsExpandDepth: 0,
            defaultModelExpandDepth: 3,
            displayRequestDuration: true,
            persistAuthorization: true,
            filter: true,
            tryItOutEnabled: true,
        })
    </script>
</body>
</html>

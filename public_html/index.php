<?php
// Database connection - FIX PATH
require_once __DIR__ . '/../config.php';  // ✅ Fixed path, remove /../

// Get dynamic content from database
try {
    $hero_content = getHeroContent();
    $features = getFeatures();
    $platform_cards = getPlatformCards();
    $insights_tabs = getInsightsTabs();
    $articles = getArticlesByCategory();
    $about_cards = getAboutCards();
} catch (Exception $e) {
    error_log("Error loading content: " . $e->getMessage());
    
    // Use minimal fallback data if all else fails
    $hero_content = [
        'page_title' => 'Inspant - Sports Analytics Platform',
        'badge_icon' => '🏆',
        'badge_text' => 'Sports Analytics Platform',
        'title' => 'Revolutionize Your Sports Performance',
        'subtitle' => 'Unlock championship-level insights with our cutting-edge sports analytics platform.',
        'cta_text' => 'Get Started'
    ];
    
    $features = [];
    $platform_cards = [];
    $insights_tabs = [];
    $articles = [];
    $about_cards = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($hero_content['page_title'] ?? 'Inspant - Sports Analytics Platform'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ===== CSS VARIABLES ===== */
        :root {
            /* Dark Theme Colors */
            --primary-bg: #0a0a0a;
            --secondary-bg: #111111;
            --surface-bg: #1a1a1a;
            --elevated-bg: #222222;
            
            /* Accent Colors */
            --accent-primary: #3b82f6;
            --accent-secondary: #2563eb;
            --accent-success: #10b981;
            --accent-warning: #f59e0b;
            --accent-sport: #f97316;
            
            /* Text Colors */
            --text-primary: #ffffff;
            --text-secondary: #e5e5e5;
            --text-tertiary: #a3a3a3;
            --text-quaternary: #737373;
            --text-disabled: #525252;
            
            /* Glass Effects */
            --glass-bg: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
            --glass-hover: rgba(255, 255, 255, 0.08);
            
            /* Shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.6);
            --shadow-3d: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(59, 130, 246, 0.1);
            
            /* Transitions */
            --transition-3d: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-base: 0.3s ease;
        }

        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--primary-bg);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ===== BACKGROUND ===== */
        .background-layer {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: 
                radial-gradient(600px circle at 20% 30%, rgba(59, 130, 246, 0.05) 0%, transparent 50%),
                radial-gradient(400px circle at 80% 70%, rgba(249, 115, 22, 0.03) 0%, transparent 50%);
        }

        /* ===== NAVIGATION ===== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 0;
            background: rgba(10, 10, 10, 0.9);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
            transition: all var(--transition-base);
        }

        .navbar.scrolled {
            padding: 0.75rem 0;
            background: rgba(10, 10, 10, 0.95);
            box-shadow: var(--shadow-md);
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-primary);
            text-decoration: none;
            transition: color var(--transition-base);
        }

        .logo span {
            color: aqua;
        }

        .logo:hover {
            color: var(--accent-secondary);
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
            align-items: center;
        }

        .nav-link {
            color: var(--text-tertiary);
            text-decoration: none;
            font-weight: 500;
            position: relative;
            padding: 0.5rem 0;
            transition: color var(--transition-base);
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent-primary);
            transition: width var(--transition-base);
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--text-primary);
        }

        .nav-link.active::after {
            width: 100%;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-cta {
            background: var(--accent-primary);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background var(--transition-base);
        }

        .nav-cta:hover {
            background: var(--accent-secondary);
        }

        /* ===== MOBILE TOGGLE ===== */
        .mobile-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            gap: 4px;
            width: 24px;
            height: 20px;
            background: transparent;
            border: none;
            padding: 0;
        }

        .mobile-toggle span {
            display: block;
            width: 100%;
            height: 2px;
            background: var(--text-primary);
            transition: all var(--transition-base);
            transform-origin: center;
        }

        .mobile-toggle.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 5px);
        }

        .mobile-toggle.active span:nth-child(2) {
            opacity: 0;
            transform: scale(0);
        }

        .mobile-toggle.active span:nth-child(3) {
            transform: rotate(-45deg) translate(7px, -6px);
        }

        /* ===== MOBILE NAVIGATION ===== */
        .mobile-nav {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(10, 10, 10, 0.98);
            backdrop-filter: blur(20px);
            z-index: 999;
            padding: 6rem 2rem 2rem;
            opacity: 0;
            visibility: hidden;
            transition: all var(--transition-base);
        }

        .mobile-nav.active {
            display: block;
            opacity: 1;
            visibility: visible;
        }

        .mobile-nav-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 3rem;
        }

        .mobile-nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 1.5rem;
            font-weight: 600;
            padding: 1rem 0;
            border-bottom: 1px solid var(--glass-border);
            transition: color var(--transition-base);
        }

        .mobile-nav-link:hover,
        .mobile-nav-link.active {
            color: var(--accent-primary);
        }

        .mobile-nav-cta {
            background: var(--accent-primary);
            color: white;
            padding: 1rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            display: block;
            transition: background var(--transition-base);
        }

        .mobile-nav-cta:hover {
            background: var(--accent-secondary);
        }

        /* ===== HERO SECTION ===== */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }

        .hero-title {
            font-size: clamp(3rem, 8vw, 6rem);
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, var(--text-primary), var(--accent-primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: clamp(1.125rem, 2vw, 1.25rem);
            color: var(--text-tertiary);
            line-height: 1.7;
            margin-bottom: 2.5rem;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ===== BUTTONS ===== */
        .btn-primary {
            background: var(--accent-primary);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background var(--transition-base);
        }

        .btn-primary:hover {
            background: var(--accent-secondary);
        }

        .btn-secondary {
            background: var(--glass-bg);
            color: var(--text-primary);
            padding: 1rem 2rem;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(10px);
            transition: all var(--transition-base);
        }

        .btn-secondary:hover {
            background: var(--glass-hover);
            border-color: var(--accent-primary);
        }

        /* ===== PAGE SECTIONS ===== */
        .page-section {
            display: none;
            min-height: 100vh;
            padding: 6rem 0 4rem;
        }

        .page-section.active {
            display: block;
        }

        .section-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-subtitle {
            font-size: 0.875rem;
            color: var(--accent-primary);
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: clamp(2.5rem, 5vw, 3.5rem);
            font-weight: 800;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .section-description {
            font-size: 1.125rem;
            color: var(--text-tertiary);
            line-height: 1.7;
            max-width: 600px;
            margin: 0 auto;
        }

        /* ===== CARDS ===== */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
            perspective: 1000px;
        }

        .pro-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2rem;
            backdrop-filter: blur(10px);
            transition: all var(--transition-3d);
            position: relative;
            cursor: pointer;
            transform: translateY(0) rotateX(0);
            opacity: 1;
            transform-style: preserve-3d;
            will-change: transform;
        }

        .pro-card:hover {
            background: var(--glass-hover);
            border-color: var(--accent-primary);
            transform: translateY(-8px) rotateX(var(--rotate-x, 0deg)) rotateY(var(--rotate-y, 0deg)) translateZ(20px);
            box-shadow: var(--shadow-3d);
        }

        .card-icon-container {
            width: 60px;
            height: 60px;
            background: var(--accent-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all var(--transition-3d);
            transform-style: preserve-3d;
        }

        .pro-card:hover .card-icon-container {
            transform: scale(1.1) rotateY(10deg) translateZ(10px);
            background: var(--accent-secondary);
            box-shadow: 0 8px 16px rgba(59, 130, 246, 0.3);
        }

        .card-title {
            font-size: 1.375rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .card-description {
            color: var(--text-tertiary);
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .card-features {
            list-style: none;
        }

        .card-features li {
            padding: 0.5rem 0;
            color: var(--text-quaternary);
            position: relative;
            padding-left: 1.25rem;
        }

        .card-features li::before {
            content: '•';
            position: absolute;
            left: 0;
            color: var(--accent-primary);
            font-weight: bold;
        }

        .pro-card:hover .card-features li {
            color: var(--text-tertiary);
        }

        /* ===== COMING SOON BADGE ===== */
        .coming-soon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: linear-gradient(45deg, var(--accent-warning), #ff6b35);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 10;
            box-shadow: var(--shadow-md);
        }

        .coming-soon-card {
            position: relative;
            opacity: 0.8;
            pointer-events: none;
        }

        .coming-soon-card:hover {
            opacity: 0.9;
            transform: translateY(-4px) rotateX(0deg) rotateY(0deg) translateZ(10px);
        }

        /* ===== TABS ===== */
        .content-tabs {
            display: flex;
            gap: 2rem;
            margin-bottom: 3rem;
            border-bottom: 1px solid var(--glass-border);
            overflow-x: auto;
            overflow-y: scroll;       /* biar tetap bisa scroll */
            scrollbar-width: none;   
        }

        .tab-button {
            background: none;
            border: none;
            color: var(--text-tertiary);
            font-weight: 600;
            padding: 1rem 0;
            cursor: pointer;
            position: relative;
            white-space: nowrap;
            transition: color var(--transition-base);
        }

        .tab-button::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent-primary);
            transition: width var(--transition-base);
        }

        .tab-button.active,
        .tab-button:hover {
            color: var(--text-primary);
        }

        .tab-button.active::after {
            width: 100%;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* ===== ARTICLES ===== */
        .articles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
            perspective: 1000px;
        }

        .article-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            overflow: hidden;
            transition: all var(--transition-3d);
            backdrop-filter: blur(10px);
            cursor: pointer;
            transform: translateY(0) rotateX(0);
            opacity: 1;
            transform-style: preserve-3d;
            will-change: transform;
        }

        .article-card:hover {
            background: var(--glass-hover);
            transform: translateY(-8px) rotateX(var(--rotate-x, 0deg)) rotateY(var(--rotate-y, 0deg)) translateZ(20px);
            box-shadow: var(--shadow-3d);
        }

        .article-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-sport));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            transition: transform var(--transition-3d);
            transform-style: preserve-3d;
        }

        .article-card:hover .article-image {
            transform: scale(1.05) translateZ(10px);
        }

        .article-content {
            padding: 1.5rem;
        }

        .article-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            color: var(--text-disabled);
        }

        .article-category {
            background: var(--accent-primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .article-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--text-primary);
            line-height: 1.4;
        }

        .article-excerpt {
            color: var(--text-tertiary);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .article-link {
            color: var(--accent-primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: color var(--transition-base);
        }

        .article-link:hover {
            color: var(--accent-secondary);
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 1200px) {
            .nav-container,
            .section-container {
                padding: 0 1.5rem;
            }
        }

        @media (max-width: 968px) {
            .cards-grid,
            .articles-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 1.5rem;
            }

            .content-tabs {
                gap: 1.5rem;
            }

            .tab-button {
                font-size: 0.9rem;
                padding: 0.75rem 0;
            }
        }

        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .mobile-toggle {
                display: flex;
            }

            .nav-container {
                padding: 0 1rem;
            }

            .hero {
                padding: 1rem;
                min-height: 90vh;
            }

            .hero-title {
                font-size: clamp(2.5rem, 8vw, 4rem);
            }

            .hero-subtitle {
                font-size: 1rem;
                margin-bottom: 2rem;
            }

            .hero-actions {
                flex-direction: column;
                align-items: center;
                gap: 1rem;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
                max-width: 280px;
                justify-content: center;
                padding: 1rem 1.5rem;
            }

            .page-section {
                padding: 4rem 0 2rem;
            }

            .section-container {
                padding: 0 1rem;
            }

            .section-title {
                font-size: clamp(2rem, 6vw, 2.5rem);
                margin-bottom: 1rem;
            }

            .section-description {
                font-size: 1rem;
            }

            .cards-grid,
            .articles-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                margin-top: 2rem;
            }

            .pro-card,
            .article-card {
                padding: 1.5rem;
            }

            .card-title {
                font-size: 1.25rem;
            }

            .content-tabs {
                gap: 0.5rem;
                padding-bottom: 0.5rem;
                margin-bottom: 2rem;
            }

            .tab-button {
                font-size: 0.875rem;
                padding: 0.75rem 0.25rem;
                min-width: auto;
                flex: 1;
                text-align: center;
            }

            .article-image {
                height: 160px;
                font-size: 1.5rem;
            }

            .article-content {
                padding: 1rem;
            }

            .article-title {
                font-size: 1.125rem;
            }

            /* Disable 3D effects on mobile */
            .pro-card:hover,
            .article-card:hover {
                transform: translateY(-4px);
                box-shadow: var(--shadow-lg);
            }

            .pro-card:hover .card-icon-container,
            .article-card:hover .article-image {
                transform: none;
            }
        }

        @media (max-width: 480px) {
            .nav-container {
                padding: 0 0.75rem;
            }

            .logo {
                font-size: 1.5rem;
            }

            .hero {
                padding: 0.75rem;
            }

            .hero-badge {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
                margin-bottom: 1.5rem;
            }

            .hero-title {
                font-size: clamp(2rem, 8vw, 3rem);
                margin-bottom: 1rem;
            }

            .hero-subtitle {
                font-size: 0.95rem;
                line-height: 1.6;
                margin-bottom: 1.5rem;
            }

            .btn-primary,
            .btn-secondary {
                padding: 0.875rem 1.25rem;
                font-size: 0.95rem;
            }

            .section-container {
                padding: 0 0.75rem;
            }

            .section-header {
                margin-bottom: 2.5rem;
            }

            .section-subtitle {
                font-size: 0.8rem;
            }

            .pro-card,
            .article-card {
                padding: 1.25rem;
                border-radius: 12px;
            }

            .card-icon-container {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
                margin-bottom: 1rem;
            }

            .card-title {
                font-size: 1.1rem;
                margin-bottom: 0.75rem;
            }

            .card-description,
            .article-excerpt {
                font-size: 0.9rem;
                line-height: 1.5;
            }

            .card-features li {
                font-size: 0.85rem;
                padding: 0.375rem 0;
            }

            .content-tabs {
                margin-bottom: 1.5rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                -ms-overflow-style: none;
            }

            .content-tabs::-webkit-scrollbar {
                display: none;
            }

            .tab-button {
                font-size: 0.8rem;
                padding: 0.625rem 0.5rem;
                white-space: nowrap;
                min-width: 80px;
            }

            .article-meta {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }

            .article-category {
                font-size: 0.7rem;
                padding: 0.2rem 0.6rem;
            }
        }

        /* Tablet adjustments */
        @media (min-width: 768px) and (max-width: 1024px) {
            .cards-grid,
            .articles-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.75rem;
            }

            .hero-title {
                font-size: clamp(3.5rem, 8vw, 5rem);
            }

            .section-title {
                font-size: clamp(2.5rem, 5vw, 3rem);
            }

            .content-tabs {
                gap: 1.75rem;
                justify-content: center;
            }

            .tab-button {
                padding: 1rem 0.5rem;
            }
        }

        /* Performance optimizations */
        .pro-card,
        .article-card,
        .btn-primary,
        .btn-secondary {
            will-change: transform;
            backface-visibility: hidden;
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                transition-duration: 0.01ms !important;
                transform: none !important;
            }
        }

        /* Matikan efek 3D hover pada kartu artikel */
        .article-card:hover {
            transform: none !important;   /* Hapus transformasi */
            transition: none !important;  /* Hapus animasi */
        }

    </style>
</head>
<body>
    <!-- Background -->
    <div class="background-layer"></div>

    <!-- Navigation -->
    <nav class="navbar" id="navbar">
        <div class="nav-container">
            <a href="#" class="logo" data-page="home">
                Inspant<span>.</span>
            </a>
            
            <ul class="nav-links">
                <li><a href="#" class="nav-link active" data-page="home">Home</a></li>
                <li><a href="#" class="nav-link" data-page="features">Analytics</a></li>
                <li><a href="#" class="nav-link" data-page="platform">Platform</a></li>
                <li><a href="#" class="nav-link" data-page="insights">Insights</a></li>
                <li><a href="#" class="nav-link" data-page="about">About</a></li>
            </ul>
            
            <div class="nav-actions">
                <a href="./register.html" class="nav-cta">Get Started</a>
                <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle mobile menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <!-- Mobile Navigation -->
    <div class="mobile-nav" id="mobileNav">
        <ul class="mobile-nav-links">
            <li><a href="#" class="mobile-nav-link active" data-page="home">Home</a></li>
            <li><a href="#" class="mobile-nav-link" data-page="features">Analytics</a></li>
            <li><a href="#" class="mobile-nav-link" data-page="platform">Platform</a></li>
            <li><a href="#" class="mobile-nav-link" data-page="insights">Insights</a></li>
            <li><a href="#" class="mobile-nav-link" data-page="about">About</a></li>
        </ul>
        <a href="./register.html" class="mobile-nav-cta">Get Started</a>
    </div>

    <!-- Hero Section -->
    <section class="page-section active" id="home">
        <div class="hero">
            <div class="hero-content">
                <div class="hero-badge">
                    <span><?php echo htmlspecialchars($hero_content['badge_icon'] ?? '🏆'); ?></span>
                    <?php echo htmlspecialchars($hero_content['badge_text'] ?? 'Sports Analytics Platform'); ?>
                </div>
                
                <h1 class="hero-title">
                    <?php echo htmlspecialchars($hero_content['title'] ?? 'Revolutionize Your Sports Performance'); ?>
                </h1>
                
                <p class="hero-subtitle">
                    <?php echo htmlspecialchars($hero_content['subtitle'] ?? 'Unlock championship-level insights with our cutting-edge sports analytics platform. Track, analyze, and optimize performance with real-time data visualization and AI-powered insights.'); ?>
                </p>
                
                <div class="hero-actions">
                    <a href="./register.html" class="btn-primary" data-page="features">
                        <?php echo htmlspecialchars($hero_content['cta_text'] ?? 'Explore Analytics'); ?>
                        <span>→</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="page-section" id="features">
        <div class="section-container">
            <div class="section-header">
                <div class="section-subtitle">Analytics Solutions</div>
                <h2 class="section-title">Professional Sports Analytics</h2>
                <p class="section-description">
                    Comprehensive analytics tools designed for sports professionals, teams, and organizations to gain competitive advantages through data-driven insights.
                </p>
            </div>
            
            <div class="cards-grid">
                <?php if (!empty($features)): ?>
                    <?php foreach($features as $feature): ?>
                    <div class="pro-card <?php echo ($feature['coming_soon'] ?? false) ? 'coming-soon-card' : ''; ?>">
                        <?php if($feature['coming_soon'] ?? false): ?>
                        <div class="coming-soon">Coming Soon</div>
                        <?php endif; ?>
                        <div class="card-icon-container"><?php echo htmlspecialchars($feature['icon'] ?? '📊'); ?></div>
                        <h3 class="card-title"><?php echo htmlspecialchars($feature['title'] ?? 'Feature Title'); ?></h3>
                        <p class="card-description">
                            <?php echo htmlspecialchars($feature['description'] ?? 'Feature description'); ?>
                        </p>
                        <ul class="card-features">
                            <?php 
                            $features_list = json_decode($feature['features'] ?? '[]', true) ?: [];
                            foreach($features_list as $feature_item): 
                            ?>
                            <li><?php echo htmlspecialchars($feature_item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="pro-card">
                        <div class="card-icon-container">📊</div>
                        <h3 class="card-title">Performance Analytics</h3>
                        <p class="card-description">
                            Advanced analytics tools for tracking and optimizing athletic performance.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Platform Section -->
    <section class="page-section" id="platform">
        <div class="section-container">
            <div class="section-header">
                <div class="section-subtitle">Multi-Platform Access</div>
                <h2 class="section-title">Everywhere You Compete</h2>
                <p class="section-description">
                    Access your sports analytics from any device, anywhere. Our platform provides consistent performance across all platforms for seamless coaching and analysis.
                </p>
            </div>
            
            <div class="cards-grid">
                <?php if (!empty($platform_cards)): ?>
                    <?php foreach($platform_cards as $card): ?>
                    <div class="pro-card <?php echo ($card['coming_soon'] ?? false) ? 'coming-soon-card' : ''; ?>">
                        <?php if($card['coming_soon'] ?? false): ?>
                        <div class="coming-soon">Coming Soon</div>
                        <?php endif; ?>
                        <div class="card-icon-container"><?php echo htmlspecialchars($card['icon'] ?? '🌐'); ?></div>
                        <h3 class="card-title"><?php echo htmlspecialchars($card['title'] ?? 'Platform Title'); ?></h3>
                        <p class="card-description">
                            <?php echo htmlspecialchars($card['description'] ?? 'Platform description'); ?>
                        </p>
                        <ul class="card-features">
                            <?php 
                            $features_list = json_decode($card['features'] ?? '[]', true) ?: [];
                            foreach($features_list as $feature_item): 
                            ?>
                            <li><?php echo htmlspecialchars($feature_item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="pro-card">
                        <div class="card-icon-container">🌐</div>
                        <h3 class="card-title">Web Platform</h3>
                        <p class="card-description">
                            Access your analytics dashboard from any web browser.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Insights Section -->
    <section class="page-section" id="insights">
        <div class="section-container">
            <div class="section-header">
                <div class="section-subtitle">Sports Intelligence</div>
                <h2 class="section-title">Latest Sports Analytics Insights</h2>
                <p class="section-description">
                    Stay ahead of the game with cutting-edge sports analytics research, industry trends, and performance optimization strategies.
                </p>
            </div>
            
            <?php if (!empty($insights_tabs)): ?>
            <div class="content-tabs">
                <?php 
                $first_tab = true;
                foreach($insights_tabs as $tab): 
                ?>
                <button class="tab-button <?php echo $first_tab ? 'active' : ''; ?>" data-tab="<?php echo htmlspecialchars($tab['slug'] ?? 'performance'); ?>">
                    <?php echo htmlspecialchars($tab['name'] ?? 'Tab'); ?>
                </button>
                <?php 
                $first_tab = false;
                endforeach; 
                ?>
            </div>
            
            <?php 
            $first_content = true;
            foreach($insights_tabs as $tab): 
            ?>
            <div class="tab-content <?php echo $first_content ? 'active' : ''; ?>" id="<?php echo htmlspecialchars($tab['slug'] ?? 'performance'); ?>">
                <div class="articles-grid">
                    <?php 
                    $tab_slug = $tab['slug'] ?? 'performance';
                    $tab_articles = $articles[$tab_slug] ?? [];
                    if (!empty($tab_articles)):
                        foreach($tab_articles as $article): 
                    ?>
                    <article class="article-card">
                        <div class="article-image"><?php echo htmlspecialchars($article['icon'] ?? '📰'); ?></div>
                        <div class="article-content">
                            <div class="article-meta">
                                <span class="article-category"><?php echo htmlspecialchars($article['category'] ?? 'Article'); ?></span>
                                <span><?php echo date('M j, Y', strtotime($article['date_published'] ?? 'now')); ?></span>
                            </div>
                            <h3 class="article-title"><?php echo htmlspecialchars($article['title'] ?? 'Article Title'); ?></h3>
                            <p class="article-excerpt">
                                <?php echo htmlspecialchars($article['excerpt'] ?? 'Article excerpt'); ?>
                            </p>
                            <a href="<?php echo htmlspecialchars($article['link_url'] ?? '#'); ?>" class="article-link">
                                <?php echo htmlspecialchars($article['link_text'] ?? 'Read more'); ?> →
                            </a>
                        </div>
                    </article>
                    <?php 
                        endforeach;
                    else: 
                    ?>
                    <article class="article-card">
                        <div class="article-image">📰</div>
                        <div class="article-content">
                            <div class="article-meta">
                                <span class="article-category">Coming Soon</span>
                                <span><?php echo date('M j, Y'); ?></span>
                            </div>
                            <h3 class="article-title">Content Coming Soon</h3>
                            <p class="article-excerpt">
                                We're working on bringing you the latest insights and content for this section.
                            </p>
                            <a href="#" class="article-link">
                                Stay tuned →
                            </a>
                        </div>
                    </article>
                    <?php endif; ?>
                </div>
            </div>
            <?php 
            $first_content = false;
            endforeach; 
            ?>
            <?php else: ?>
            <div class="articles-grid">
                <article class="article-card">
                    <div class="article-image">📰</div>
                    <div class="article-content">
                        <div class="article-meta">
                            <span class="article-category">Coming Soon</span>
                            <span><?php echo date('M j, Y'); ?></span>
                        </div>
                        <h3 class="article-title">Content Coming Soon</h3>
                        <p class="article-excerpt">
                            We're working on bringing you the latest insights and analytics content.
                        </p>
                        <a href="#" class="article-link">
                            Stay tuned →
                        </a>
                    </div>
                </article>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- About Section -->
    <section class="page-section" id="about">
        <div class="section-container">
            <div class="section-header">
                <div class="section-subtitle">Our Mission</div>
                <h2 class="section-title">Advancing Sports Through Analytics</h2>
                <p class="section-description">
                    We're revolutionizing sports performance by making advanced analytics accessible to athletes, coaches, and teams at every level of competition.
                </p>
            </div>
            
            <div class="cards-grid">
                <?php if (!empty($about_cards)): ?>
                    <?php foreach($about_cards as $card): ?>
                    <div class="pro-card">
                        <div class="card-icon-container"><?php echo htmlspecialchars($card['icon'] ?? '🎯'); ?></div>
                        <h3 class="card-title"><?php echo htmlspecialchars($card['title'] ?? 'Our Focus'); ?></h3>
                        <p class="card-description">
                            <?php echo htmlspecialchars($card['description'] ?? 'Our mission description'); ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="pro-card">
                        <div class="card-icon-container">🎯</div>
                        <h3 class="card-title">Our Mission</h3>
                        <p class="card-description">
                            Empowering athletes and coaches with data-driven insights for peak performance.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <script>
        // ===== SPORTS PRO APP CLASS =====
        class SportsProApp {
            constructor() {
                this.currentPage = 'home';
                this.currentTab = '<?php echo ($insights_tabs[0]['slug'] ?? 'performance'); ?>';
                this.navbar = document.getElementById('navbar');
                this.mobileNav = document.getElementById('mobileNav');
                this.mobileToggle = document.getElementById('mobileToggle');
                
                this.init();
            }

            // ===== INITIALIZATION =====
            init() {
                this.bindEvents();
                this.handleScroll();
                this.setup3DCards();
            }

            // ===== EVENT BINDING =====
            bindEvents() {
                // Navigation events
                this.bindNavigationEvents();
                
                // Tab events
                this.bindTabEvents();
                
                // Scroll events
                this.bindScrollEvents();
                
                // Mobile menu events
                this.bindMobileMenuEvents();
            }

            bindNavigationEvents() {
                document.querySelectorAll('[data-page]').forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const page = link.getAttribute('data-page');
                        this.navigateToPage(page);
                    });
                });
            }

            bindTabEvents() {
                document.querySelectorAll('.tab-button').forEach(button => {
                    button.addEventListener('click', (e) => {
                        e.preventDefault();
                        const tab = button.getAttribute('data-tab');
                        this.switchTab(tab);
                    });
                });
            }

            bindScrollEvents() {
                window.addEventListener('scroll', () => {
                    this.handleScroll();
                }, { passive: true });
            }

            bindMobileMenuEvents() {
                // Mobile toggle button
                if (this.mobileToggle) {
                    this.mobileToggle.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.toggleMobileMenu();
                    });
                }

                // Mobile navigation links
                document.querySelectorAll('.mobile-nav-link, .mobile-nav-cta').forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        const page = link.getAttribute('data-page');
                        if (page) {
                            this.navigateToPage(page);
                            this.closeMobileMenu();
                        }
                    });
                });

                // Close mobile menu when clicking outside
                if (this.mobileNav) {
                    this.mobileNav.addEventListener('click', (e) => {
                        if (e.target === this.mobileNav) {
                            this.closeMobileMenu();
                        }
                    });
                }

                // Handle escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.mobileNav && this.mobileNav.classList.contains('active')) {
                        this.closeMobileMenu();
                    }
                });
            }

            // ===== MOBILE MENU FUNCTIONS =====
            toggleMobileMenu() {
                if (this.mobileNav && this.mobileNav.classList.contains('active')) {
                    this.closeMobileMenu();
                } else {
                    this.openMobileMenu();
                }
            }

            openMobileMenu() {
                if (this.mobileNav && this.mobileToggle) {
                    this.mobileNav.classList.add('active');
                    this.mobileToggle.classList.add('active');
                    document.body.style.overflow = 'hidden';
                    
                    // Update active link in mobile menu
                    this.updateMobileNavActiveLink();
                }
            }

            closeMobileMenu() {
                if (this.mobileNav && this.mobileToggle) {
                    this.mobileNav.classList.remove('active');
                    this.mobileToggle.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }

            updateMobileNavActiveLink() {
                document.querySelectorAll('.mobile-nav-link').forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('data-page') === this.currentPage) {
                        link.classList.add('active');
                    }
                });
            }

            // ===== 3D CARD EFFECTS =====
            setup3DCards() {
                const activeSection = document.querySelector('.page-section.active');
                if (!activeSection) return;

                const allCards = activeSection.querySelectorAll('.pro-card, .article-card');
                if (allCards.length === 0) return;

                allCards.forEach(card => {
                    this.add3DMouseTracking(card);
                });
            }

            add3DMouseTracking(card) {
                if (card.dataset.has3d) return;

                const handleMouseMove = (e) => {
                    if (!card.matches(':hover')) return;

                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const centerX = rect.width / 2;
                    const centerY = rect.height / 2;

                    const rotateX = (y - centerY) / 10;
                    const rotateY = (centerX - x) / 10;

                    card.style.setProperty('--rotate-x', `${-rotateX}deg`);
                    card.style.setProperty('--rotate-y', `${rotateY}deg`);
                };

                const handleMouseLeave = () => {
                    card.style.setProperty('--rotate-x', '0deg');
                    card.style.setProperty('--rotate-y', '0deg');
                };

                card.addEventListener('mousemove', handleMouseMove, { passive: true });
                card.addEventListener('mouseleave', handleMouseLeave);
                card.dataset.has3d = 'true';
            }

            // ===== NAVIGATION FUNCTIONS =====
            navigateToPage(page) {
                if (page === this.currentPage) return;

                // Hide current page
                const currentSection = document.getElementById(this.currentPage);
                if (currentSection) {
                    currentSection.classList.remove('active');
                }

                // Show new page
                const newSection = document.getElementById(page);
                if (newSection) {
                    newSection.classList.add('active');
                    this.animatePageContent(newSection);
                }

                // Update navigation
                this.updateNavigation(page);
                
                this.currentPage = page;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            updateNavigation(page) {
                // Update desktop navigation
                document.querySelectorAll('.nav-link').forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('data-page') === page) {
                        link.classList.add('active');
                    }
                });

                // Update mobile navigation
                this.updateMobileNavActiveLink();
            }

            // ===== TAB FUNCTIONS =====
            switchTab(tab) {
                if (tab === this.currentTab) return;

                // Hide current tab content
                const currentContent = document.getElementById(this.currentTab);
                if (currentContent) {
                    currentContent.classList.remove('active');
                }

                // Show new tab content
                const newContent = document.getElementById(tab);
                if (newContent) {
                    newContent.classList.add('active');
                    this.animateTabContent(newContent);
                }

                // Update tab buttons
                document.querySelectorAll('.tab-button').forEach(button => {
                    button.classList.remove('active');
                    if (button.getAttribute('data-tab') === tab) {
                        button.classList.add('active');
                    }
                });

                this.currentTab = tab;
            }

            // ===== SCROLL HANDLING =====
            handleScroll() {
                const scrollY = window.scrollY;
                
                if (this.navbar) {
                    if (scrollY > 50) {
                        this.navbar.classList.add('scrolled');
                    } else {
                        this.navbar.classList.remove('scrolled');
                    }
                }
            }

            // ===== ANIMATION FUNCTIONS =====
            animatePageContent(section) {
                setTimeout(() => {
                    this.setup3DCards();
                }, 100);
            }

            animateTabContent(content) {
                setTimeout(() => {
                    this.setup3DCards();
                }, 100);
            }
        }

        // ===== INITIALIZATION =====
        document.addEventListener('DOMContentLoaded', () => {
            new SportsProApp();
        });

        // ===== REDUCED MOTION SUPPORT =====
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.documentElement.style.setProperty('--transition-3d', '0.01ms');
        }
    </script>
</body>
</html>
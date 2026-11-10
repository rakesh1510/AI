<?php
require_once __DIR__ . '/helpers.php';
$title = "Terms of Service — AIHubpedia";
$meta_desc = "Terms of Service for AIHubpedia";
require __DIR__ . '/layout_top.php';
?>

<style>
  .legal-page {
    max-width: 950px;
    margin: 0 auto;
    padding: 40px 20px 70px;
  }

  .legal-hero {
    text-align: center;
    margin-bottom: 32px;
  }

  .legal-badge {
    display: inline-block;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(59, 130, 246, 0.10);
    color: #2563eb;
    font-size: 0.85rem;
    font-weight: 700;
    margin-bottom: 14px;
  }

  .legal-title {
    font-size: clamp(2rem, 4vw, 3rem);
    font-weight: 800;
    margin-bottom: 10px;
    color: #0f172a;
  }

  .legal-subtitle {
    max-width: 760px;
    margin: 0 auto;
    font-size: 1.05rem;
    line-height: 1.75;
    color: #475569;
  }

  .legal-grid {
    display: grid;
    gap: 22px;
    margin-top: 30px;
  }

  .legal-card {
    background: #fff;
    border-radius: 18px;
    padding: 26px;
    border: 1px solid rgba(0,0,0,0.08);
    box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    transition: 0.25s;
  }

  .legal-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 35px rgba(0,0,0,0.08);
  }

  .legal-card h3 {
    font-size: 1.2rem;
    margin-bottom: 12px;
    font-weight: 800;
  }

  .legal-card p {
    color: #334155;
    line-height: 1.8;
  }

  .contact-link {
    color: #2563eb;
    font-weight: 600;
    text-decoration: none;
  }

  .contact-link:hover {
    text-decoration: underline;
  }

  .contact-box {
    margin-top: 15px;
    padding: 14px;
    border-radius: 12px;
    background: rgba(37, 99, 235, 0.05);
    border: 1px solid rgba(37, 99, 235, 0.1);
  }

  .mini-note {
    font-size: 0.9rem;
    color: #64748b;
  }
</style>

<section class="legal-page">

  <div class="legal-hero">
    <div class="legal-badge">Legal Terms</div>
    <h1 class="legal-title">Terms of Service</h1>
    <p class="legal-subtitle">
      These Terms of Service govern the use of <strong>AIHubpedia</strong>,
      a product of <strong>TrustNova Tech</strong>, operated by Rakesh Prajapati.
      By using this website, you agree to the terms set out below.
    </p>
  </div>

  <div class="legal-grid">

    <div class="legal-card">
      <h3>1. Acceptance of Terms</h3>
      <p>
        By accessing and using AIHubpedia, a product of TrustNova Tech,
        operated by Rakesh Prajapati, you agree to these Terms of Service.
        If you do not agree with these terms, please do not use this website.
      </p>
    </div>

    <div class="legal-card">
      <h3>2. Purpose of the Website</h3>
      <p>
        AIHubpedia is an informational platform that provides listings and descriptions
        of artificial intelligence tools and services. The website is intended to help
        users discover and compare AI tools.
      </p>
    </div>

    <div class="legal-card">
      <h3>3. Accuracy of Information</h3>
      <p>
        We strive to keep the information accurate and up to date.
        However, we do not guarantee completeness or accuracy.
        Users should verify details directly with tool providers.
      </p>
    </div>

    <div class="legal-card">
      <h3>4. External Links</h3>
      <p>
        This website contains links to third-party websites.
        We are not responsible for their content, services, or privacy practices.
      </p>
    </div>

    <div class="legal-card">
      <h3>5. User Interaction</h3>
      <p>
        Features like clicks, likes, and dislikes are provided for improving the platform.
        Any misuse or manipulation is not allowed.
      </p>
    </div>

    <div class="legal-card">
      <h3>6. Intellectual Property</h3>
      <p>
        All content on this website belongs to AIHubpedia unless stated otherwise.
        Unauthorized use is not permitted.
      </p>
    </div>

    <div class="legal-card">
      <h3>7. Limitation of Liability</h3>
      <p>
        The operator is not liable for damages resulting from use of this website
        or third-party tools. Content is provided "as is".
      </p>
    </div>

    <div class="legal-card">
      <h3>8. Changes to the Service</h3>
      <p>
        We may modify or discontinue the website or these terms at any time.
      </p>
    </div>

    <div class="legal-card">
      <h3>9. Governing Law</h3>
      <p>
        These terms are governed by the laws of Germany.
      </p>
    </div>

    <div class="legal-card">
      <h3>10. Contact</h3>
      <p>If you have questions, contact us:</p>

      <div class="contact-box">
        <p>
          <strong>Email:</strong>
          <a href="mailto:contact@aihubpedia.com" class="contact-link">contact@aihubpedia.com</a><br>
          <span class="mini-note">
            Business inquiries:
            <a href="mailto:info@trustnovatech.de" class="contact-link">info@trustnovatech.de</a>
          </span>
        </p>
      </div>
    </div>

  </div>

</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
<?php
require_once __DIR__ . '/helpers.php';
$title = "Impressum — AIHubpedia";
$meta_desc = "Legal notice for AIHubpedia";
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
    letter-spacing: 0.3px;
    margin-bottom: 14px;
  }

  .legal-title {
    font-size: clamp(2rem, 4vw, 3rem);
    line-height: 1.15;
    font-weight: 800;
    margin: 0 0 10px;
    color: #0f172a;
  }

  .legal-subtitle {
    max-width: 700px;
    margin: 0 auto;
    font-size: 1.03rem;
    line-height: 1.75;
    color: #475569;
  }

  .legal-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 22px;
    margin-top: 30px;
  }

  .legal-card {
    background: #ffffff;
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
  }

  .legal-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.10);
    border-color: rgba(37, 99, 235, 0.22);
  }

  .legal-card h3 {
    margin: 0 0 16px;
    font-size: 1.25rem;
    font-weight: 800;
    color: #111827;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .legal-card h3::before {
    content: "";
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    flex: 0 0 10px;
  }

  .legal-card p {
    margin: 0;
    color: #334155;
    font-size: 1rem;
    line-height: 1.85;
  }

  .legal-card strong {
    color: #0f172a;
  }

  .contact-link {
    color: #2563eb;
    text-decoration: none;
    font-weight: 700;
  }

  .contact-link:hover {
    text-decoration: underline;
  }

  .contact-box {
    margin-top: 18px;
    padding: 16px 18px;
    border-radius: 16px;
    background: linear-gradient(180deg, rgba(37, 99, 235, 0.05), rgba(124, 58, 237, 0.04));
    border: 1px solid rgba(37, 99, 235, 0.10);
  }

  .mini-note {
    display: inline-block;
    margin-top: 6px;
    color: #64748b;
    font-size: 0.92rem;
  }

  @media (max-width: 640px) {
    .legal-page {
      padding: 28px 16px 50px;
    }

    .legal-card {
      padding: 22px 18px;
      border-radius: 16px;
    }

    .legal-card p {
      font-size: 0.97rem;
      line-height: 1.75;
    }
  }
</style>

<section class="legal-page">
  <div class="legal-hero">
    <div class="legal-badge">Legal Information</div>
    <h1 class="legal-title">Impressum</h1>
    <p class="legal-subtitle">
      This legal notice provides the required provider information for AIHubpedia,
      a product of TrustNova Tech.
    </p>
  </div>

  <div class="legal-grid">

    <div class="legal-card">
      <h3>Information according to § 5 DDG</h3>
      <p>
        <strong>AIHubpedia</strong><br>
        A product of <strong>TrustNova Tech</strong><br>
        Operated by: Rakesh Prajapati<br>
        In den Mühläckern 35<br>
        74196 Neuenstadt am Kocher<br>
        Germany
      </p>

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

    <div class="legal-card">
      <h3>Responsible for content according to § 18 para. 2 MStV</h3>
      <p>
        Rakesh Prajapati<br>
        In den Mühläckern 35<br>
        74196 Neuenstadt am Kocher<br>
        Germany
      </p>
    </div>

    <div class="legal-card">
      <h3>Liability for Content</h3>
      <p>
        The contents of our pages were created with the greatest care. However, we cannot guarantee
        the accuracy, completeness, or timeliness of the content. As a service provider, we are responsible
        for our own content on these pages according to general laws.
      </p>
    </div>

    <div class="legal-card">
      <h3>Liability for Links</h3>
      <p>
        Our website contains links to external AI tools and third-party websites.
        We have no influence over the content of these external sites.
        Therefore, we cannot accept liability for their content.
        The respective provider or operator of the pages is always responsible for the content of the linked pages.
      </p>
    </div>

    <div class="legal-card">
      <h3>Copyright</h3>
      <p>
        The content and works created by the site operator on these pages are subject to copyright law.
        Reproduction, editing, distribution, or any kind of use outside the limits of copyright law
        require the written consent of the respective author or creator.
      </p>
    </div>

  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
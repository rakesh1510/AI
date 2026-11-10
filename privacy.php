<?php
require_once __DIR__ . '/helpers.php';
$title = "Privacy Policy — AIHubpedia";
$meta_desc = "Privacy Policy for AIHubpedia";
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
    max-width: 760px;
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

  .legal-card h4 {
    margin: 18px 0 10px;
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
  }

  .legal-card p {
    margin: 0 0 12px;
    color: #334155;
    font-size: 1rem;
    line-height: 1.85;
  }

  .legal-card p:last-child {
    margin-bottom: 0;
  }

  .legal-card strong {
    color: #0f172a;
  }

  .legal-card ul {
    margin: 10px 0 14px 20px;
    padding: 0;
    color: #334155;
  }

  .legal-card li {
    margin: 8px 0;
    line-height: 1.75;
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

    .legal-card p,
    .legal-card li {
      font-size: 0.97rem;
      line-height: 1.75;
    }
  }
</style>

<section class="legal-page">
  <div class="legal-hero">
    <div class="legal-badge">Data Protection</div>
    <h1 class="legal-title">Privacy Policy</h1>
    <p class="legal-subtitle">
      This privacy policy explains how AIHubpedia, a product of TrustNova Tech,
      processes personal data when you use this website.
    </p>
  </div>

  <div class="legal-grid">

    <div class="legal-card">
      <h3>1. Controller</h3>
      <p>
        The controller responsible for data processing on this website is:
      </p>

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
      <h3>2. Data protection at a glance</h3>

      <h4>General information</h4>
      <p>
        The following information provides an overview of what happens to your personal data
        when you visit this website. Personal data means any information that can identify you
        personally, either directly or indirectly.
      </p>

      <h4>How we collect data</h4>
      <p>
        Some data is collected when you provide it to us directly, for example when contacting us.
        Other data is collected automatically or technically when you visit the website,
        such as browser information, access times, and server-related data.
      </p>

      <h4>Why we process data</h4>
      <p>
        We process data to provide the website, ensure technical stability and security,
        prevent misuse, and generate internal statistics relating to website interactions.
      </p>
    </div>

    <div class="legal-card">
      <h3>3. Legal bases for processing</h3>
      <p>
        Where personal data is processed in connection with providing this website,
        the processing is carried out on the basis of the applicable provisions of the
        General Data Protection Regulation (GDPR).
      </p>

      <ul>
        <li><strong>Art. 6(1)(f) GDPR</strong> – legitimate interests, especially for secure, stable, and abuse-resistant website operation</li>
        <li><strong>Art. 6(1)(b) GDPR</strong> – where processing is necessary to respond to a direct request or inquiry from you</li>
        <li><strong>Art. 6(1)(a) GDPR</strong> – if consent is requested in a specific case</li>
      </ul>

      <p>
        Our legitimate interests include maintaining website security, preventing fraud or misuse,
        and improving the reliability of our services.
      </p>
    </div>

    <div class="legal-card">
      <h3>4. Hosting and server log files</h3>
      <p>
        The hosting provider automatically collects and stores information in so-called server log files
        that your browser transmits automatically.
      </p>

      <ul>
        <li>Browser type and browser version</li>
        <li>Operating system used</li>
        <li>Referrer URL</li>
        <li>Hostname of the accessing device</li>
        <li>Time of the server request</li>
        <li>IP address</li>
      </ul>

      <p>
        This data is processed to ensure the secure, stable, and technically reliable operation of the website,
        including the detection and prevention of misuse or attacks.
      </p>
    </div>

    <div class="legal-card">
      <h3>5. Interaction data (clicks, likes, dislikes)</h3>
      <p>
        AIHubpedia provides interaction features such as click tracking and voting
        to help display tool popularity and improve the platform.
      </p>

      <p>
        To operate these features fairly and reduce abuse, we may process limited technical data such as:
      </p>

      <ul>
        <li>Tool identifier (for example, <code>tool_slug</code>)</li>
        <li>A generated visitor identifier</li>
        <li>A hashed version of the IP address (<code>ip_hash</code>)</li>
        <li>Date and time of the interaction</li>
      </ul>

      <p>
        This data is processed solely for technical functionality, fraud prevention,
        abuse prevention, and aggregated internal usage statistics.
      </p>
    </div>

    <div class="legal-card">
      <h3>6. Cookies and similar storage technologies</h3>
      <p>
        This website may use technically necessary cookies or similar browser storage
        to ensure proper functionality. This may include storing a visitor identifier
        needed for features such as voting, interaction tracking, and abuse prevention.
      </p>

      <p>
        No advertising or marketing cookies are used on this website, unless explicitly stated otherwise
        in a separate consent mechanism.
      </p>
    </div>

    <div class="legal-card">
      <h3>7. External links</h3>
      <p>
        Our website contains links to external AI tools and third-party websites.
        We have no influence over the content, security, or privacy practices of those external websites.
        Please review their privacy policies separately before using their services.
      </p>
    </div>

    <div class="legal-card">
      <h3>8. Data retention</h3>
      <p>
        Personal data is stored only for as long as necessary for the relevant processing purpose,
        especially technical operation, website security, abuse prevention, and internal statistics,
        unless a longer retention period is required by law.
      </p>
    </div>

    <div class="legal-card">
      <h3>9. Your rights under data protection law</h3>
      <p>
        Subject to the applicable legal requirements, you have the following rights:
      </p>

      <ul>
        <li>Right of access to your stored personal data</li>
        <li>Right to rectification of inaccurate data</li>
        <li>Right to erasure of personal data</li>
        <li>Right to restriction of processing</li>
        <li>Right to object to processing</li>
        <li>Right to data portability where applicable</li>
        <li>Right to withdraw consent at any time, where processing is based on consent</li>
        <li>Right to lodge a complaint with a supervisory authority</li>
      </ul>
    </div>

    <div class="legal-card">
      <h3>10. Contact regarding privacy</h3>
      <p>
        If you have questions regarding data protection on this website,
        or if you wish to exercise your rights, you can contact us at:
      </p>

      <p>
        <strong>Email:</strong>
        <a href="mailto:contact@aihubpedia.com" class="contact-link">contact@aihubpedia.com</a>
      </p>
    </div>

    <div class="legal-card">
      <h3>11. Changes to this privacy policy</h3>
      <p>
        We reserve the right to update this privacy policy from time to time
        so that it remains in line with legal requirements or reflects changes
        to our website and services.
      </p>
    </div>

  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
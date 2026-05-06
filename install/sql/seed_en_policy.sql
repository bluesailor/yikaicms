-- ikaiCMS - English Privacy Policy / Terms of Service seed
-- Run this on an EN install to replace the auto-translated stub with proper English content.
-- Usage:
--   mysql -u root -p ikaicms < install/sql/seed_en_policy.sql
--
-- Adjust prefix below if not 'enkai_'.
SET @P = 'enkai_';

UPDATE enkai_contents SET
    title = 'Privacy Policy',
    summary = 'How we collect, use, and protect your personal information.',
    seo_title = 'Privacy Policy',
    seo_keywords = 'privacy policy, personal information, data protection',
    seo_description = 'Learn how we collect, use, and protect your personal information.',
    content = '<h2>Privacy Policy</h2>
<p><em>Last updated: 2026-05-01</em></p>
<p>This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our website. Please read this Privacy Policy carefully. If you do not agree with the terms, please do not access the site.</p>

<h3>1. Information We Collect</h3>
<p>We may collect personal information that you voluntarily provide, including:</p>
<ul>
<li><strong>Contact details</strong>: name, email address, phone number, company name when you submit an inquiry or contact us.</li>
<li><strong>Usage data</strong>: IP address, browser type, pages visited, time stamps and referrer URLs collected automatically via cookies and standard server logs.</li>
<li><strong>Cookies</strong>: small text files used to remember preferences and analyze site traffic.</li>
</ul>

<h3>2. How We Use Your Information</h3>
<p>We use the collected information to:</p>
<ul>
<li>Respond to your inquiries and provide customer service.</li>
<li>Improve our products, services, and website experience.</li>
<li>Send you administrative information such as inquiry confirmations.</li>
<li>Comply with legal obligations and enforce our Terms of Service.</li>
</ul>
<p>We do not sell your personal information to third parties.</p>

<h3>3. Information Sharing</h3>
<p>We may share your information only:</p>
<ul>
<li>With service providers who assist us in operating the website (under confidentiality obligations).</li>
<li>When required by law, court order, or government regulation.</li>
<li>To protect our rights, property, or safety, or that of our users.</li>
</ul>

<h3>4. Data Security</h3>
<p>We implement industry-standard administrative, technical, and physical safeguards to protect your personal information. However, no method of transmission over the Internet is 100% secure.</p>

<h3>5. Your Rights</h3>
<p>Subject to applicable law, you may have the right to access, correct, delete, or restrict the processing of your personal information. To exercise these rights, please contact us using the details below.</p>

<h3>6. Cookies and Tracking</h3>
<p>You can control cookies through your browser settings. Disabling cookies may affect the functionality of the website.</p>

<h3>7. Third-Party Links</h3>
<p>Our website may contain links to third-party sites. We are not responsible for the privacy practices of those sites and recommend you review their privacy policies.</p>

<h3>8. Children\'s Privacy</h3>
<p>Our services are not directed to children under 13. We do not knowingly collect personal information from children. If you believe we have collected such information, please contact us immediately.</p>

<h3>9. Changes to This Policy</h3>
<p>We may update this Privacy Policy from time to time. The updated version will be posted on this page with a revised "Last updated" date.</p>

<h3>10. Contact Us</h3>
<p>If you have questions about this Privacy Policy, please contact us via the contact form on our website or by email.</p>'
WHERE slug = 'privacy';

UPDATE enkai_contents SET
    title = 'Terms of Service',
    summary = 'The terms governing your use of our website and services.',
    seo_title = 'Terms of Service',
    seo_keywords = 'terms of service, terms of use, user agreement',
    seo_description = 'Read the terms governing your use of our website and services.',
    content = '<h2>Terms of Service</h2>
<p><em>Last updated: 2026-05-01</em></p>
<p>Welcome to our website. By accessing or using this site, you agree to be bound by these Terms of Service ("Terms"). If you do not agree, please do not use the site.</p>

<h3>1. Acceptance of Terms</h3>
<p>Your access to and use of the website is conditioned on your acceptance of and compliance with these Terms. These Terms apply to all visitors, users, and others who access or use the website.</p>

<h3>2. Use of the Website</h3>
<p>You agree to use the website only for lawful purposes and in a manner that does not infringe the rights of, or restrict or inhibit the use of this website by, any third party. Prohibited behavior includes:</p>
<ul>
<li>Harassing, threatening, or causing distress to others.</li>
<li>Transmitting obscene, offensive, or otherwise objectionable content.</li>
<li>Disrupting the normal flow of dialogue or interfering with the website operation.</li>
<li>Attempting to gain unauthorized access to any part of the website.</li>
</ul>

<h3>3. Intellectual Property</h3>
<p>All content on this website — including text, graphics, logos, images, audio clips, digital downloads, and software — is the property of the website operator or its content suppliers and is protected by international copyright laws. Reproduction, distribution, or modification without prior written permission is prohibited.</p>

<h3>4. Product Information and Availability</h3>
<p>We strive to ensure that product descriptions, pricing, and availability information are accurate. However, we do not warrant the accuracy, completeness, or timeliness of such information. We reserve the right to correct errors and update information at any time without prior notice.</p>

<h3>5. User Submissions</h3>
<p>By submitting any inquiry or content via the website, you grant us a non-exclusive, royalty-free, worldwide license to use, reproduce, and adapt that content for the purpose of providing our services to you. You represent that you have all necessary rights to submit such content.</p>

<h3>6. Disclaimer of Warranties</h3>
<p>The website is provided on an "as is" and "as available" basis. We disclaim all warranties, express or implied, including but not limited to warranties of merchantability, fitness for a particular purpose, and non-infringement.</p>

<h3>7. Limitation of Liability</h3>
<p>In no event shall we be liable for any indirect, incidental, special, consequential, or punitive damages, including loss of profits, data, or goodwill, arising out of or in connection with your use of the website.</p>

<h3>8. Indemnification</h3>
<p>You agree to indemnify and hold us harmless from any claims, losses, or damages arising out of your breach of these Terms or your use of the website in violation of applicable law.</p>

<h3>9. Modifications to Terms</h3>
<p>We reserve the right to modify these Terms at any time. Changes become effective upon posting on this page. Your continued use of the website after changes constitutes acceptance of the revised Terms.</p>

<h3>10. Governing Law</h3>
<p>These Terms are governed by and construed in accordance with the laws of the jurisdiction in which the website operator is established, without regard to conflict of law principles.</p>

<h3>11. Contact</h3>
<p>If you have questions about these Terms, please contact us via the contact form on our website.</p>'
WHERE slug = 'terms';

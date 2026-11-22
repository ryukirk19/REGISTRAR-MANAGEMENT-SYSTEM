<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$studentCount = (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn();
$requestCount = (int) db()->query('SELECT COUNT(*) FROM requests')->fetchColumn();
$completeCount = (int) db()->query("SELECT COUNT(*) FROM (SELECT s.id,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status = 'Complete') AS done,
        (SELECT COUNT(*) FROM credentials c WHERE c.student_id = s.id AND c.status <> 'N/A') AS total
     FROM students s) t WHERE t.total > 0 AND t.done = t.total")->fetchColumn();
$completeRate = $studentCount ? (int) round($completeCount / $studentCount * 100) : 0;

site_header('home');
?>
<section class="hero">
    <div class="hero-inner">
        <div class="hero-copy">
            <span class="hero-badge"><b>Proposed system</b> Grade 12 – Versatility · November 2026</span>
            <h1>Every student record,<br><em>finally within reach.</em></h1>
            <p class="hero-lede">
                REGIS+ is a registrar records management system that unites the student index, credential
                checklist, request tracker, and follow-up log into one searchable workflow built for an
                office that still works from master sheets, Excel lists, and filing cabinets.
            </p>
            <div class="hero-actions">
                <a class="btn btn-brass btn-lg" href="/login.php">Open the system <?= icon('arrow-right') ?></a>
                <a class="btn btn-ghost btn-lg" href="#modules">Explore the modules</a>
            </div>
            <p class="hero-note"><?= icon('shield') ?> Role-based access · Audit trail · Data minimization</p>
            <div class="hero-stats">
                <div><strong><?= $studentCount ?></strong><span>Student records</span></div>
                <div><strong><?= $requestCount ?></strong><span>Logged requests</span></div>
                <div><strong><?= $completeRate ?>%</strong><span>Files complete</span></div>
                <div><strong>3</strong><span>SDGs served</span></div>
            </div>
        </div>

        <div class="mock-wrap">
            <div class="mock">
                <div class="mock-bar"><i></i><i></i><i></i><em>regisplus · registrar dashboard</em></div>
                <div class="mock-body">
                    <div class="mock-side">
                        <div class="m-brand"></div>
                        <span class="on" style="width:86%"></span>
                        <span style="width:72%"></span>
                        <span style="width:80%"></span>
                        <span style="width:60%"></span>
                        <span style="width:76%"></span>
                        <span style="width:52%"></span>
                    </div>
                    <div class="mock-main">
                        <div class="mock-head"><b>Registrar overview</b><i></i></div>
                        <div class="mock-kpis">
                            <div class="mock-kpi"><u></u><b><?= $studentCount ?></b><small>Records</small></div>
                            <div class="mock-kpi"><u></u><b><?= max(0, $completeCount) ?></b><small>Complete</small></div>
                            <div class="mock-kpi"><u></u><b><?= $requestCount ?></b><small>Requests</small></div>
                        </div>
                        <div class="mock-panel">
                            <div class="mock-row">
                                <span class="dot"></span>
                                <span class="lines"><u></u><u></u></span>
                                <span class="pill pill-info"><span class="pill-dot"></span>Processing</span>
                            </div>
                            <div class="mock-row">
                                <span class="dot" style="background:#f7efdd"></span>
                                <span class="lines"><u></u><u></u></span>
                                <span class="pill pill-warn"><span class="pill-dot"></span>Pending</span>
                            </div>
                            <div class="mock-row">
                                <span class="dot"></span>
                                <span class="lines"><u></u><u></u></span>
                                <span class="pill pill-ok"><span class="pill-dot"></span>Released</span>
                            </div>
                            <div class="mock-row">
                                <span class="dot" style="background:#f7efdd"></span>
                                <span class="lines"><u></u><u></u></span>
                                <span class="pill pill-ok"><span class="pill-dot"></span>Verified</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mock-float mock-float-1">
                <span class="ring"></span>
                <span><strong><?= $completeRate ?>% complete</strong><small>Credential checklist</small></span>
            </div>
            <div class="mock-float mock-float-2">
                <?= icon('cert') ?>
                <span><small>Certificate</small><strong>CERT-2026-0006</strong></span>
            </div>
        </div>
    </div>
</section>

<section class="section problem" id="problem">
    <div class="section-inner">
        <div class="problem-grid">
            <div class="problem-copy">
                <div class="eyebrow">Background of the problem</div>
                <blockquote>
                    Academic records are <em>permanent</em> they may be requested years after a
                    student graduates or transfers. Fragmented manual files make every lookup a gamble.
                </blockquote>
                <cite>Registrar Office · Valencia City, Bukidnon</cite>
            </div>
            <div class="pain-list">
                <div class="pain">
                    <span class="icon-box"><?= icon('alert') ?></span>
                    <div>
                        <strong>Lost or misplaced documents</strong>
                        <p>Folders live across cabinets, master sheets, and separate Excel lists, so tracing an old record depends on memory.</p>
                    </div>
                </div>
                <div class="pain">
                    <span class="icon-box"><?= icon('file') ?></span>
                    <div>
                        <strong>Incomplete student records</strong>
                        <p>Missing SF10, SF9, or elementary credentials are only discovered when a request is already overdue.</p>
                    </div>
                </div>
                <div class="pain">
                    <span class="icon-box"><?= icon('clock') ?></span>
                    <div>
                        <strong>Delays from previous schools</strong>
                        <p>Pending credentials require manual cross-checking and repeated follow-ups, with no structured record of what was sent or promised.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section modules" id="modules">
    <div class="section-inner">
        <div class="section-head center">
            <div class="eyebrow">Core functional modules</div>
            <h2>Six workflows, one registrar workspace</h2>
            <p>Each module mirrors a real Registrar Office task — from locating a folder to releasing a certification — without replacing existing approval procedures.</p>
        </div>
        <div class="module-grid">
            <article class="module">
                <span class="module-num">01</span>
                <div class="module-icon"><?= icon('records') ?></div>
                <h3>Student Record Index &amp; Locator</h3>
                <p>A searchable index of student identifiers, record codes, and exact cabinet, shelf, and folder locations for rapid retrieval.</p>
            </article>
            <article class="module brass">
                <span class="module-num">02</span>
                <div class="module-icon"><?= icon('check') ?></div>
                <h3>Document Checklist &amp; Missing Flags</h3>
                <p>Standard credential checklist for SF10, SF9, and elementary records that automatically flags incomplete files.</p>
            </article>
            <article class="module">
                <span class="module-num">03</span>
                <div class="module-icon"><?= icon('requests') ?></div>
                <h3>Document Request Tracker</h3>
                <p>Every request logged with its date, document, assigned personnel, processing status, and release date.</p>
            </article>
            <article class="module brass">
                <span class="module-num">04</span>
                <div class="module-icon"><?= icon('followup') ?></div>
                <h3>Follow-Up Log</h3>
                <p>Structured communication record for pending credentials contact dates, concerns raised, and next actions with previous schools.</p>
            </article>
            <article class="module">
                <span class="module-num">05</span>
                <div class="module-icon"><?= icon('cert') ?></div>
                <h3>Certification &amp; Transfer Support</h3>
                <p>Issued certifications are numbered and printable, while incoming and outgoing transfers are tracked end to end.</p>
            </article>
            <article class="module brass">
                <span class="module-num">06</span>
                <div class="module-icon"><?= icon('reports') ?></div>
                <h3>Reports, Monitoring &amp; Privacy</h3>
                <p>Status summaries for school monitoring alongside authorized access, data minimization, and periodic backups.</p>
            </article>
        </div>
    </div>
</section>

<section class="section workflow" id="workflow">
    <div class="section-inner">
        <div class="section-head">
            <div class="eyebrow">How the office works with REGIS+</div>
            <h2>From walk-in request to released document</h2>
            <p>The same eight-phase rollout validated with registrar personnel, compressed into a four-step daily loop.</p>
        </div>
        <div class="flow">
            <div class="flow-step">
                <div class="flow-num">1</div>
                <h3>Receive the request</h3>
                <p>Log the student, document type, purpose, requester, and assigned personnel at the counter.</p>
            </div>
            <div class="flow-step">
                <div class="flow-num">2</div>
                <h3>Locate the record</h3>
                <p>Search the index by name, LRN, or record code to get the exact cabinet, shelf, and folder.</p>
            </div>
            <div class="flow-step">
                <div class="flow-num">3</div>
                <h3>Verify the checklist</h3>
                <p>Confirm SF10, SF9, and supporting credentials; flag what is missing before processing.</p>
            </div>
            <div class="flow-step">
                <div class="flow-num">4</div>
                <h3>Release or follow up</h3>
                <p>Mark the request released or record the follow-up with the previous school and set the next action.</p>
            </div>
        </div>
    </div>
</section>

<section class="section impact" id="impact">
    <div class="section-inner">
        <div class="section-head">
            <div class="eyebrow">Sustainable Development Goals</div>
            <h2>Small office, institutional impact</h2>
            <p>REGIS+ is designed as a school-based digital records solution, aligned with three SDGs.</p>
        </div>
        <div class="impact-grid">
            <article class="impact-card">
                <span class="sdg"><b>SDG 4</b> Quality Education</span>
                <h3>Records that keep students moving</h3>
                <p>Verified academic records and certifications stay accessible for transfers, scholarships, and applications abroad.</p>
            </article>
            <article class="impact-card">
                <span class="sdg"><b>SDG 9</b> Industry &amp; Innovation</span>
                <h3>Digital tracking infrastructure</h3>
                <p>Modernizes manual recordkeeping with an index, checklist, and tracker that a small school can actually run.</p>
            </article>
            <article class="impact-card">
                <span class="sdg"><b>SDG 16</b> Strong Institutions</span>
                <h3>Accountability by design</h3>
                <p>Role-based access, an audit trail, and a follow-up log strengthen transparency in official record handling.</p>
            </article>
        </div>
    </div>
</section>

<section class="section privacy-band">
    <div class="section-inner">
        <div class="privacy-grid">
            <div class="section-head" style="margin-bottom:0">
                <div class="eyebrow">Privacy &amp; security</div>
                <h2>Controlled access, by default</h2>
                <p>Student records are confidential. REGIS+ limits exposure through account roles, logging, and data minimization — aligned with the school's data privacy rules.</p>
            </div>
            <div class="privacy-list">
                <div class="privacy-item">
                    <?= icon('lock') ?>
                    <div><strong>Authorized personnel only</strong><p>Sign-in is required; administrator, registrar, and staff roles see only what their work needs.</p></div>
                </div>
                <div class="privacy-item">
                    <?= icon('shield') ?>
                    <div><strong>Data minimization</strong><p>Only the fields needed for record tracking are stored — no unnecessary personal data.</p></div>
                </div>
                <div class="privacy-item">
                    <?= icon('audit') ?>
                    <div><strong>Audit trail</strong><p>Sign-ins, edits, releases, and follow-ups are logged for accountability.</p></div>
                </div>
                <div class="privacy-item">
                    <?= icon('download') ?>
                    <div><strong>Periodic backups</strong><p>Weekly database backups with a recovery checklist for the registrar workstation.</p></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="cta-band">
    <div class="section-inner">
        <div class="cta-card">
            <div>
                <h2>Open the registrar workspace</h2>
                <p>Sign in with your registrar account to manage the student index, document requests, and follow-up log.</p>
            </div>
            <div class="cta-actions">
                <a class="btn btn-brass btn-lg" href="/login.php">Sign in to REGIS+ <?= icon('arrow-right') ?></a>
                <a class="btn btn-ghost btn-lg" href="#modules" style="background:transparent;border-color:rgba(255,255,255,.22);color:#e9e4d6">Review the modules</a>
            </div>
        </div>
    </div>
</section>
<?php
site_footer();

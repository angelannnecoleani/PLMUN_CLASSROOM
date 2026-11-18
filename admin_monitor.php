<?php
session_start();
include 'database.php';

/* =========================================================
   ADMIN ACCESS CONTROL
========================================================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html");
    exit();
}


/* =========================================================
   GET ADMIN INFORMATION
========================================================= */

$admin_id = intval($_SESSION['user_id']);

$adminQuery = mysqli_query(
    $conn,
    "SELECT * FROM users WHERE id='$admin_id'"
);

if (!$adminQuery || mysqli_num_rows($adminQuery) == 0) {
    session_destroy();
    header("Location: login.html");
    exit();
}

$admin = mysqli_fetch_assoc($adminQuery);


/* =========================================================
   CPU USAGE
   Windows / XAMPP
========================================================= */

function getCPUUsage()
{
    $cpu = 0;

    if (PHP_OS_FAMILY === 'Windows') {

        $output = shell_exec(
            'wmic cpu get loadpercentage /value 2>NUL'
        );

        if ($output) {

            if (
                preg_match(
                    '/LoadPercentage=(\d+)/i',
                    $output,
                    $matches
                )
            ) {
                $cpu = intval($matches[1]);
            }
        }
    }

    return max(0, min(100, $cpu));
}


/* =========================================================
   MEMORY USAGE
   Windows / XAMPP
========================================================= */

function getMemoryUsage()
{
    $usedPercent = 0;

    if (PHP_OS_FAMILY === 'Windows') {

        $output = shell_exec(
            'wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value 2>NUL'
        );

        if ($output) {

            preg_match(
                '/TotalVisibleMemorySize=(\d+)/i',
                $output,
                $totalMatch
            );

            preg_match(
                '/FreePhysicalMemory=(\d+)/i',
                $output,
                $freeMatch
            );

            if (
                isset($totalMatch[1]) &&
                isset($freeMatch[1])
            ) {

                $total = floatval($totalMatch[1]);
                $free = floatval($freeMatch[1]);

                if ($total > 0) {

                    $used =
                        $total - $free;

                    $usedPercent =
                        ($used / $total) * 100;
                }
            }
        }
    }

    return max(
        0,
        min(
            100,
            round($usedPercent)
        )
    );
}


/* =========================================================
   DISK USAGE
========================================================= */

function getDiskUsage()
{
    $drive = 'C:';

    $total = @disk_total_space($drive);
    $free = @disk_free_space($drive);

    if (
        $total === false ||
        $free === false ||
        $total <= 0
    ) {
        return 0;
    }

    $used = $total - $free;

    return round(
        ($used / $total) * 100
    );
}


/* =========================================================
   NETWORK USAGE
========================================================= */

function getNetworkUsage()
{
    /*
       Windows does not provide a simple PHP function
       for real-time network percentage.

       We use the Windows network adapter performance
       counter to get a practical activity percentage.
    */

    $network = 0;

    if (PHP_OS_FAMILY === 'Windows') {

        $output = shell_exec(
            'typeperf "\Network Interface(*)\Bytes Total/sec" -sc 1 2>NUL'
        );

        if ($output) {

            $lines = preg_split(
                "/\r\n|\n|\r/",
                trim($output)
            );

            if (count($lines) >= 2) {

                $lastLine =
                    $lines[count($lines) - 1];

                /*
                   Extract numbers from the result.
                */

                preg_match_all(
                    '/"([^"]+)"/',
                    $lastLine,
                    $matches
                );

                if (!empty($matches[1])) {

                    $values = [];

                    foreach (
                        $matches[1]
                        as $value
                    ) {

                        if (
                            is_numeric(
                                str_replace(
                                    ',',
                                    '.',
                                    $value
                                )
                            )
                        ) {

                            $values[] =
                                floatval(
                                    str_replace(
                                        ',',
                                        '.',
                                        $value
                                    )
                                );
                        }
                    }

                    if (!empty($values)) {

                        $totalBytes =
                            array_sum($values);

                        /*
                           Convert network traffic into
                           a display percentage.

                           100 Mbps ≈ 12.5 MB/s.
                        */

                        $network =
                            ($totalBytes / 12500000) * 100;

                        $network =
                            round($network);
                    }
                }
            }
        }
    }

    return max(
        0,
        min(
            100,
            $network
        )
    );
}


/* =========================================================
   GET MONITOR VALUES
========================================================= */

$cpuUsage =
    getCPUUsage();

$memoryUsage =
    getMemoryUsage();

$diskUsage =
    getDiskUsage();

$networkUsage =
    getNetworkUsage();


/* =========================================================
   STATUS FUNCTION
========================================================= */

function getStatus($value)
{
    if ($value >= 80) {
        return 'Critical';
    }

    if ($value >= 60) {
        return 'Warning';
    }

    return 'Good';
}


$cpuStatus =
    getStatus($cpuUsage);

$memoryStatus =
    getStatus($memoryUsage);

$diskStatus =
    getStatus($diskUsage);

$networkStatus =
    getStatus($networkUsage);


/* =========================================================
   AJAX REQUEST
   Used for automatic monitor refresh
========================================================= */

if (
    isset($_GET['monitor']) &&
    $_GET['monitor'] === '1'
) {

    header(
        'Content-Type: application/json'
    );

    echo json_encode([
        'cpu' => $cpuUsage,
        'memory' => $memoryUsage,
        'disk' => $diskUsage,
        'network' => $networkUsage,

        'cpuStatus' => $cpuStatus,
        'memoryStatus' => $memoryStatus,
        'diskStatus' => $diskStatus,
        'networkStatus' => $networkStatus
    ]);

    exit();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        System Monitor | PLMUN Classroom
    </title>


    <link
        rel="shortcut icon"
        href="img/plmun logo.png"
        type="image/x-icon"
    >


    <!-- EXISTING ADMIN CSS -->

    <link
        rel="stylesheet"
        href="admin_dashboard.css"
    >


    <!-- FONT AWESOME -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <style>

        /* =====================================================
           RESET
        ===================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }


        /* =====================================================
           BODY
        ===================================================== */

        body {
            background: #f7f7f7;
            font-family: Arial, sans-serif;
            overflow-x: hidden;
        }


        /* =====================================================
           MAIN CONTENT
        ===================================================== */

        .monitor-content {

            padding: 18px 22px 40px;

        }


        /* =====================================================
           PAGE TITLE
        ===================================================== */

        .monitor-title {

            font-size: 27px;
            font-weight: 700;
            color: #111;

            margin-bottom: 14px;

        }


        /* =====================================================
           MONITOR CARDS
        ===================================================== */

        .monitor-cards {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 14px;

            margin-bottom: 14px;

        }


        .monitor-card {

            background: #fff;

            border: 1px solid #d8d8d8;

            min-height: 182px;

            padding: 12px 10px;

            text-align: center;

        }


        .monitor-card h3 {

            font-size: 19px;

            font-weight: 600;

            color: #111;

            margin-bottom: 7px;

        }


        /* =====================================================
           CIRCLE
        ===================================================== */

        .usage-circle {

            width: 104px;

            height: 104px;

            border-radius: 50%;

            border: 6px solid #50b957;

            margin: 0 auto 6px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #fff;

            transition:
                border-color .3s ease;

        }


        .usage-circle.warning {

            border-color: #ffe600;

        }


        .usage-circle.critical {

            border-color: #ef4444;

        }


        .usage-number {

            font-size: 34px;

            font-weight: 700;

            color: #111;

        }


        /* =====================================================
           STATUS
        ===================================================== */

        .usage-status {

            font-size: 13px;

            color: #444;

        }


        /* =====================================================
           SYSTEM PERFORMANCE
        ===================================================== */

        .performance-box {

            background: #fff;

            border: 1px solid #d8d8d8;

            padding: 17px 20px 22px;

        }


        .performance-title {

            font-size: 21px;

            font-weight: 600;

            color: #111;

            margin-bottom: 18px;

        }


        /* =====================================================
           CHART
        ===================================================== */

        .chart-container {

            height: 265px;

            border: 1px solid #d8d8d8;

            position: relative;

            background: #fff;

            overflow: hidden;

        }


        .chart-grid {

            position: absolute;

            inset: 0;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            padding: 15px 15px 20px 15px;

        }


        .chart-line {

            border-top: 1px dashed #e4e4e4;

            width: 100%;

        }


        .chart-label {

            position: absolute;

            left: 7px;

            font-size: 10px;

            color: #888;

        }


        .label-100 {

            top: 8px;

        }


        .label-75 {

            top: 25%;

        }


        .label-50 {

            top: 50%;

        }


        .label-25 {

            top: 75%;

        }


        .label-0 {

            bottom: 5px;

        }


        /* =====================================================
           SVG GRAPH
        ===================================================== */

        #performanceChart {

            position: absolute;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

        }


        /* =====================================================
           LEGEND
        ===================================================== */

        .chart-legend {

            display: flex;

            justify-content: center;

            gap: 25px;

            margin-top: 10px;

            font-size: 12px;

            color: #555;

        }


        .legend-item {

            display: flex;

            align-items: center;

            gap: 5px;

        }


        .legend-dot {

            width: 9px;

            height: 9px;

            border-radius: 50%;

            background: #555;

        }


        /* =====================================================
           LAST UPDATED
        ===================================================== */

        .last-updated {

            text-align: right;

            font-size: 11px;

            color: #888;

            margin-top: 7px;

        }


        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 1000px) {

            .monitor-cards {

                grid-template-columns:
                    repeat(2, 1fr);

            }

        }


        @media (max-width: 768px) {

            .monitor-content {

                padding: 18px 15px 30px;

            }


            .monitor-title {

                font-size: 23px;

            }


            .monitor-cards {

                grid-template-columns: 1fr;

            }


            .monitor-card {

                min-height: 170px;

            }


            .performance-box {

                padding: 15px;

            }


            .chart-container {

                height: 220px;

            }

        }


        @media (max-width: 480px) {

            .usage-circle {

                width: 95px;

                height: 95px;

            }


            .usage-number {

                font-size: 30px;

            }

        }

    </style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<div
    class="admin-sidebar"
    id="adminSidebar"
>


    <!-- SIDEBAR HEADER -->

    <div class="admin-sidebar-header">

        <h1>
            Admin
        </h1>

    </div>


    <!-- MENU -->

    <ul class="admin-menu">


        <!-- DASHBOARD -->

        <li>

            <a href="admin_dashboard.php">

                <i class="fa-solid fa-house"></i>

                <span>
                    Dashboard
                </span>

            </a>

        </li>


        <!-- USER MANAGEMENT -->

        <li>

            <a href="admin_users.php">

                <i class="fa-solid fa-user"></i>

                <span>
                    User Management
                </span>

            </a>

        </li>


        <!-- SYSTEM SETTINGS -->

        <li>

            <a href="admin_settings.php">

                <i class="fa-solid fa-gear"></i>

                <span>
                    System Settings
                </span>

            </a>

        </li>

        <!-- SYSTEM MONITOR -->

        <li>

            <a
                href="admin_monitor.php"
                class="active"
            >

                <i class="fa-solid fa-desktop"></i>

                <span>
                    System Monitor
                </span>

            </a>

        </li>


        <!-- REPORTS -->

        <li>

            <a href="admin_reports.php">

                <i class="fa-solid fa-wave-square"></i>

                <span>
                    Reports & Analytics
                </span>

            </a>

        </li>


        <!-- LOGS -->

        <li>

            <a href="admin_logs.php">

                <i class="fa-regular fa-clock"></i>

                <span>
                    Logs & Activity
                </span>

            </a>

        </li>


        <!-- SECURITY -->

        <li>

            <a href="admin_security.php">

                <i class="fa-solid fa-shield-halved"></i>

                <span>
                    Backup & Security
                </span>

            </a>

        </li>


    </ul>


    <!-- =====================================================
         LOGOUT
    ====================================================== -->

    <div class="admin-logout">

        <a href="logout.php">

            <i
                class="fa-solid fa-right-from-bracket"
            ></i>

            <span>
                Log out
            </span>

        </a>

    </div>


</div>


<!-- =========================================================
     MOBILE OVERLAY
========================================================= -->

<div
    class="admin-overlay"
    id="adminOverlay"
    onclick="closeAdminSidebar()"
></div>


<!-- =========================================================
     MAIN
========================================================= -->

<div
    class="admin-main"
    id="adminMain"
>


    <!-- =====================================================
         TOPBAR
    ====================================================== -->

    <header class="admin-topbar">


        <div class="admin-top-left">


            <!-- MOBILE MENU -->

            <button
                class="admin-mobile-btn"
                onclick="toggleAdminSidebar()"
            >

                <i class="fa-solid fa-bars"></i>

            </button>


            <!-- LOGO -->

            <img
                src="img/classroom logo.png"
                alt="PLMUN Classroom"
                class="admin-logo"
            >


        </div>


        <!-- RIGHT SIDE -->

        <div class="admin-top-right">


            <!-- ADMIN NAME -->

            <div class="admin-user-name">

                <span>

                    <?php
                    echo htmlspecialchars(
                        $admin['name']
                    );
                    ?>

                </span>

            </div>


            <!-- PROFILE -->

            <a
                href="profile.php"
                class="admin-profile"
            >

                <?php if (
                    !empty(
                        $admin['profile_image']
                    ) &&
                    $admin['profile_image']
                    !== 'default.png'
                ): ?>

                    <img
                        src="uploads/<?php
                            echo htmlspecialchars(
                                $admin[
                                    'profile_image'
                                ]
                            );
                        ?>"
                        alt="Admin"
                    >

                <?php else: ?>

                    <i class="fa-solid fa-user"></i>

                <?php endif; ?>

            </a>


        </div>


    </header>


    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <main class="monitor-content">


        <!-- PAGE TITLE -->

        <h2 class="monitor-title">
            System Monitor
        </h2>


        <!-- =================================================
             MONITOR CARDS
        ================================================== -->

        <div class="monitor-cards">


            <!-- =================================================
                 CPU
            ================================================== -->

            <div class="monitor-card">

                <h3>
                    CPU Usage
                </h3>


                <div
                    class="usage-circle <?php

                        if ($cpuStatus === 'Warning') {
                            echo 'warning';
                        }

                        if ($cpuStatus === 'Critical') {
                            echo 'critical';
                        }

                    ?>"
                    id="cpuCircle"
                >

                    <span
                        class="usage-number"
                        id="cpuValue"
                    >
                        <?php
                        echo $cpuUsage;
                        ?>%
                    </span>

                </div>


                <div
                    class="usage-status"
                    id="cpuStatus"
                >
                    <?php
                    echo $cpuStatus;
                    ?>
                </div>

            </div>


            <!-- =================================================
                 MEMORY
            ================================================== -->

            <div class="monitor-card">

                <h3>
                    Memory Usage
                </h3>


                <div
                    class="usage-circle <?php

                        if ($memoryStatus === 'Warning') {
                            echo 'warning';
                        }

                        if ($memoryStatus === 'Critical') {
                            echo 'critical';
                        }

                    ?>"
                    id="memoryCircle"
                >

                    <span
                        class="usage-number"
                        id="memoryValue"
                    >
                        <?php
                        echo $memoryUsage;
                        ?>%
                    </span>

                </div>


                <div
                    class="usage-status"
                    id="memoryStatus"
                >
                    <?php
                    echo $memoryStatus;
                    ?>
                </div>

            </div>


            <!-- =================================================
                 DISK
            ================================================== -->

            <div class="monitor-card">

                <h3>
                    Disk Space
                </h3>


                <div
                    class="usage-circle <?php

                        if ($diskStatus === 'Warning') {
                            echo 'warning';
                        }

                        if ($diskStatus === 'Critical') {
                            echo 'critical';
                        }

                    ?>"
                    id="diskCircle"
                >

                    <span
                        class="usage-number"
                        id="diskValue"
                    >
                        <?php
                        echo $diskUsage;
                        ?>%
                    </span>

                </div>


                <div
                    class="usage-status"
                    id="diskStatus"
                >
                    <?php
                    echo $diskStatus;
                    ?>
                </div>

            </div>


            <!-- =================================================
                 NETWORK
            ================================================== -->

            <div class="monitor-card">

                <h3>
                    Network
                </h3>


                <div
                    class="usage-circle <?php

                        if ($networkStatus === 'Warning') {
                            echo 'warning';
                        }

                        if ($networkStatus === 'Critical') {
                            echo 'critical';
                        }

                    ?>"
                    id="networkCircle"
                >

                    <span
                        class="usage-number"
                        id="networkValue"
                    >
                        <?php
                        echo $networkUsage;
                        ?>%
                    </span>

                </div>


                <div
                    class="usage-status"
                    id="networkStatus"
                >
                    <?php
                    echo $networkStatus;
                    ?>
                </div>

            </div>


        </div>


        <!-- =================================================
             SYSTEM PERFORMANCE
        ================================================== -->

        <div class="performance-box">


            <h3 class="performance-title">
                System Performance
            </h3>


            <div class="chart-container">


                <!-- GRID -->

                <div class="chart-grid">

                    <div class="chart-line"></div>

                    <div class="chart-line"></div>

                    <div class="chart-line"></div>

                    <div class="chart-line"></div>

                    <div class="chart-line"></div>

                </div>


                <!-- LABELS -->

                <span
                    class="chart-label label-100"
                >
                    100
                </span>


                <span
                    class="chart-label label-75"
                >
                    75
                </span>


                <span
                    class="chart-label label-50"
                >
                    50
                </span>


                <span
                    class="chart-label label-25"
                >
                    25
                </span>


                <span
                    class="chart-label label-0"
                >
                    0
                </span>


                <!-- SVG -->

                <svg
                    id="performanceChart"
                    viewBox="0 0 800 250"
                    preserveAspectRatio="none"
                >

                    <polyline
                        id="cpuLine"
                        fill="none"
                        stroke="#4caf50"
                        stroke-width="3"
                        points=""
                    />

                    <polyline
                        id="memoryLine"
                        fill="none"
                        stroke="#ffdf00"
                        stroke-width="3"
                        points=""
                    />

                </svg>


            </div>


            <!-- LEGEND -->

            <div class="chart-legend">


                <div class="legend-item">

                    <span
                        class="legend-dot"
                        style="background:#4caf50;"
                    ></span>

                    CPU

                </div>


                <div class="legend-item">

                    <span
                        class="legend-dot"
                        style="background:#ffdf00;"
                    ></span>

                    Memory

                </div>


            </div>


            <div
                class="last-updated"
                id="lastUpdated"
            >

                Last updated:
                <?php
                echo date('h:i:s A');
                ?>

            </div>


        </div>


    </main>


</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>


/* =========================================================
   SIDEBAR
========================================================= */

function toggleAdminSidebar() {

    const sidebar =
        document.getElementById(
            'adminSidebar'
        );

    const overlay =
        document.getElementById(
            'adminOverlay'
        );

    sidebar.classList.toggle('show');

    overlay.classList.toggle('show');

}


function closeAdminSidebar() {

    const sidebar =
        document.getElementById(
            'adminSidebar'
        );

    const overlay =
        document.getElementById(
            'adminOverlay'
        );

    sidebar.classList.remove('show');

    overlay.classList.remove('show');

}


window.addEventListener(
    'resize',
    function () {

        if (window.innerWidth > 768) {

            closeAdminSidebar();

        }

    }
);


/* =========================================================
   PERFORMANCE HISTORY
========================================================= */

let cpuHistory = [];

let memoryHistory = [];


/* =========================================================
   MAXIMUM HISTORY POINTS
========================================================= */

const MAX_POINTS = 20;


/* =========================================================
   UPDATE CIRCLE
========================================================= */

function updateCircle(
    circleId,
    valueId,
    statusId,
    value,
    status
) {

    const circle =
        document.getElementById(circleId);

    const valueElement =
        document.getElementById(valueId);

    const statusElement =
        document.getElementById(statusId);


    valueElement.textContent =
        value + '%';


    statusElement.textContent =
        status;


    circle.classList.remove(
        'warning',
        'critical'
    );


    if (status === 'Warning') {

        circle.classList.add(
            'warning'
        );

    }


    if (status === 'Critical') {

        circle.classList.add(
            'critical'
        );

    }

}


/* =========================================================
   CREATE GRAPH POINTS
========================================================= */

function createPoints(history) {

    if (history.length === 0) {
        return '';
    }


    const width = 800;

    const height = 250;

    const left = 25;

    const right = 10;

    const top = 10;

    const bottom = 15;


    const graphWidth =
        width - left - right;

    const graphHeight =
        height - top - bottom;


    const points = [];


    history.forEach(
        function (value, index) {

            let x;


            if (history.length === 1) {

                x =
                    left +
                    graphWidth / 2;

            } else {

                x =
                    left +
                    (
                        index /
                        (history.length - 1)
                    ) *
                    graphWidth;

            }


            let y =
                top +
                graphHeight -
                (
                    value / 100
                ) *
                graphHeight;


            points.push(
                x.toFixed(1) +
                ',' +
                y.toFixed(1)
            );

        }
    );


    return points.join(' ');

}


/* =========================================================
   UPDATE GRAPH
========================================================= */

function updateGraph() {

    document
        .getElementById('cpuLine')
        .setAttribute(
            'points',
            createPoints(cpuHistory)
        );


    document
        .getElementById('memoryLine')
        .setAttribute(
            'points',
            createPoints(memoryHistory)
        );

}


/* =========================================================
   ADD HISTORY
========================================================= */

function addHistory(
    cpu,
    memory
) {

    cpuHistory.push(cpu);

    memoryHistory.push(memory);


    if (
        cpuHistory.length >
        MAX_POINTS
    ) {

        cpuHistory.shift();

    }


    if (
        memoryHistory.length >
        MAX_POINTS
    ) {

        memoryHistory.shift();

    }


    updateGraph();

}


/* =========================================================
   LOAD MONITOR DATA
========================================================= */

function loadMonitorData() {

    fetch(
        'admin_monitor.php?monitor=1&_=' +
        Date.now()
    )

    .then(
        response => response.json()
    )

    .then(
        data => {

            updateCircle(
                'cpuCircle',
                'cpuValue',
                'cpuStatus',
                data.cpu,
                data.cpuStatus
            );


            updateCircle(
                'memoryCircle',
                'memoryValue',
                'memoryStatus',
                data.memory,
                data.memoryStatus
            );


            updateCircle(
                'diskCircle',
                'diskValue',
                'diskStatus',
                data.disk,
                data.diskStatus
            );


            updateCircle(
                'networkCircle',
                'networkValue',
                'networkStatus',
                data.network,
                data.networkStatus
            );


            addHistory(
                data.cpu,
                data.memory
            );


            document
                .getElementById(
                    'lastUpdated'
                )
                .textContent =
                    'Last updated: ' +
                    new Date()
                        .toLocaleTimeString();

        }
    )

    .catch(
        error => {

            console.log(
                'Monitor error:',
                error
            );

        }
    );

}


/* =========================================================
   INITIAL GRAPH
========================================================= */

addHistory(
    <?php echo $cpuUsage; ?>,
    <?php echo $memoryUsage; ?>
);


/* =========================================================
   AUTO REFRESH
========================================================= */

setInterval(
    loadMonitorData,
    5000
);


</script>


</body>

</html>
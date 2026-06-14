<?php
$page_start_time = microtime(true);
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "bonavion";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}


$isLoggedIn = isset($_SESSION['customer_id']);
$customerName = isset($_SESSION['customer_name']) ? $_SESSION['customer_name'] : '';
$firstName = isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '';

$navAvatarDisplay = '';
$navInitials = '';
if ($isLoggedIn) {
    $stmt = $conn->prepare("SELECT first_name, last_name, avatar_blob FROM customer WHERE customer_id = ?");
    $stmt->bind_param("s", $_SESSION['customer_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $navInitials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
        if (!empty($row['avatar_blob'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($row['avatar_blob']);
            $navAvatarDisplay = 'data:' . $mime . ';base64,' . base64_encode($row['avatar_blob']);
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon Avion - Flight Search</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/main.css">
    <style>
        .hero {
            min-height: 75vh;
            background: linear-gradient(135deg, var(--deep), var(--navy), var(--blue));
            padding: 60px 5%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-content { max-width: 1100px; width: 100%; }
        .hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2rem, 5vw, 3rem);
            color: #fff;
            text-align: center;
            margin-bottom: 50px;
        }
        .hero h1 span { color: var(--gold); font-style: italic; }

        .search-card {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            position: relative;
        }
        .search-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--gold), var(--copper), var(--gold));
            border-radius: 20px 20px 0 0;
        }

        .trip-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 24px;
            background: var(--light);
            padding: 5px;
            border-radius: 10px;
            width: fit-content;
        }
        .trip-tabs button {
            padding: 10px 24px;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 14px;
            color: var(--muted);
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .trip-tabs button.active {
            background: #fff;
            color: var(--navy);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .search-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr auto;
            gap: 16px;
            align-items: end;
        }
        .field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .input-wrap { position: relative; }
        .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 14px;
        }
        .field input {
            width: 100%;
            padding: 14px 14px 14px 42px;
            border: 2px solid transparent;
            border-radius: 10px;
            background: var(--light);
            font-family: inherit;
            font-size: 15px;
            color: var(--text);
            outline: none;
            transition: all 0.2s;
        }
        .field input:focus {
            border-color: var(--blue);
            background: #fff;
        }
        .field input::placeholder { color: var(--muted); }

        .search-btn {
            padding: 14px 28px;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            border: none;
            border-radius: 10px;
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(30,60,114,0.3);
        }

        .passengers-field { position: relative; z-index: 10; }
        .passengers-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            min-width: 240px;
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        .passengers-dropdown.active { display: block; }
        .passengers-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .passengers-row span { font-weight: 500; }
        .counter {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .counter button {
            width: 30px; height: 30px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            font-size: 16px;
            color: var(--navy);
        }
        .counter button:hover:not(:disabled) { background: var(--light); }
        .counter button:disabled { opacity: 0.4; cursor: not-allowed; }
        .cabin-select { padding-top: 12px; border-top: 1px solid #eee; }
        .cabin-select select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
        }

        .date-picker-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        .date-picker-overlay.active { display: flex; }
        .date-picker {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            width: 340px;
            max-width: 95vw;
        }
        .date-picker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .date-picker-header h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.2rem;
            color: var(--navy);
        }
        .date-picker-header button {
            background: var(--light);
            border: none;
            width: 32px; height: 32px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
        }
        .month-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .month-nav button {
            background: var(--light);
            border: none;
            width: 36px; height: 36px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
        }
        .month-nav span { font-weight: 600; }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            text-align: center;
        }
        .calendar-grid .day-name {
            font-size: 11px;
            color: var(--muted);
            padding: 8px 0;
        }
        .calendar-grid .day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
        }
        .calendar-grid .day:hover:not(.disabled):not(.empty) { background: var(--light); }
        .calendar-grid .day.selected { background: var(--navy); color: #fff; }
        .calendar-grid .day.in-range { background: rgba(30,60,114,0.1); }
        .calendar-grid .day.disabled { color: #ccc; cursor: default; }
        .date-picker-footer {
            margin-top: 16px;
            text-align: right;
        }
        .date-picker-footer button {
            padding: 10px 24px;
            background: var(--navy);
            border: none;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
        }

        .user-welcome {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            font-weight: 500;
        }
        .user-welcome i { color: var(--gold); }
        .nav-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--gold), #b87333);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
        }
        .nav-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        @media (max-width: 900px) {
            .search-grid { grid-template-columns: 1fr 1fr; }
            .search-btn { grid-column: span 2; justify-content: center; }
        }
        @media (max-width: 600px) {
            .hero { padding: 40px 4%; min-height: auto; }
            .search-card { padding: 24px; }
            .search-grid { grid-template-columns: 1fr; }
            .search-btn { grid-column: span 1; }
            .trip-tabs { width: 100%; }
            .trip-tabs button { flex: 1; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="top-area">
        <div class="logo"><img src="image/Logo.png" alt="Bon Avion"></div>
        <div class="auth-area">
            <div class="order-dropdown">
                <button type="button" class="order-btn">My Orders ▼</button>
                <div class="dropdown-menu">
                    <a href="myOrders.php?tab=upcoming">Upcoming</a>
                    <a href="myOrders.php?tab=completed">Completed</a>
                </div>
            </div>
            
            <?php if ($isLoggedIn): ?>
                <div class="user-welcome">
                    <a href="update_profile.php" class="nav-avatar">
                        <?php if ($navAvatarDisplay): ?>
                            <img src="<?php echo $navAvatarDisplay; ?>" alt="Avatar">
                        <?php else: ?>
                            <?php echo $navInitials; ?>
                        <?php endif; ?>
                    </a>
                    <span>Welcome, <?php echo htmlspecialchars($firstName); ?>!</span>
                    <a href="logout.php" class="login-btn" style="margin-left: 10px;">Logout</a>
                </div>
            <?php else: ?>
                <div class="auth-buttons">
                    <a href="login.php" class="login-btn">Login</a>
                    <a href="register.php" class="register-btn">Register</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <nav class="main-nav">
        <ul class="nav-list">
            <li><a href="index.php" class="nav-link active">Index</a></li>
            <li><a href="recommendation.php" class="nav-link">Recommendation</a></li>
            <li><a href="AboutUs.php" class="nav-link">About Us</a></li>
            <li><a href="profile.php" class="nav-link">Profile</a></li>
        </ul>
    </nav>

    <section class="hero">
        <div class="hero-content">
            <h1>Discover the World with <span>Bon Avion</span></h1>
            <div class="search-card">
                <div class="trip-tabs">
                    <button class="active" data-type="roundtrip">Round Trip</button>
                    <button data-type="oneway">One Way</button>
                </div>
                <div class="search-grid">
                    <div class="field">
                        <label>From</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-plane-departure"></i>
                            <input type="text" id="from" placeholder="City or airport" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>To</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-plane-arrival"></i>
                            <input type="text" id="to" placeholder="City or airport" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>Dates</label>
                        <div class="input-wrap">
                            <i class="fa-regular fa-calendar"></i>
                            <input type="text" id="dates" placeholder="Select dates" readonly required>
                        </div>
                    </div>
                    <div class="field passengers-field">
                        <label>Travelers</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-user"></i>
                            <input type="text" id="passengers" value="1 Passenger, Economy" readonly>
                        </div>
                        <div class="passengers-dropdown" id="pDropdown">
                            <div class="passengers-row">
                                <span>Passengers</span>
                                <div class="counter">
                                    <button type="button" id="pMinus">−</button>
                                    <span id="pCount">1</span>
                                    <button type="button" id="pPlus">+</button>
                                </div>
                            </div>
                            <div class="cabin-select">
                                <select id="cabin">
                                    <option value="Economy">Economy</option>
                                    <option value="Business">Business</option>
                                    <option value="First Class">First Class</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button class="search-btn" id="searchBtn">
                        <i class="fa-solid fa-magnifying-glass"></i> Search Flights
                    </button>
                </div>
            </div>
        </div>
    </section>

    <div class="date-picker-overlay" id="overlay">
        <div class="date-picker">
            <div class="date-picker-header">
                <h3>Select Dates</h3>
                <button id="closeDP">✕</button>
            </div>
            <div class="month-nav">
                <button id="prevM">‹</button>
                <span id="monthTitle"></span>
                <button id="nextM">›</button>
            </div>
            <div class="calendar-grid" id="cal"></div>
            <div class="date-picker-footer">
                <button id="applyDates">Apply</button>
            </div>
        </div>
    </div>

    <script>
        const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        
        const redirectTriggers = {
            buttons: ['searchBtn', 'order-btn'],
            links: ['profile.php', 'myOrders.php']
        };

        document.addEventListener('click', function(e) {
            if (isLoggedIn) return;

            let target = e.target;
            let needRedirect = false;

            if (target.id === redirectTriggers.buttons[0] || target.closest(`#${redirectTriggers.buttons[0]}`)) {
                needRedirect = true;
            }

            if (target.classList.contains(redirectTriggers.buttons[1]) || target.closest(`.${redirectTriggers.buttons[1]}`)) {
                needRedirect = true;
            }

            if (target.tagName === 'A') {
                const href = target.getAttribute('href');
                if (href && redirectTriggers.links.some(path => href.includes(path))) {
                    needRedirect = true;
                }
            }

            if (needRedirect) {
                e.preventDefault();
                window.location.href = 'login.php';
            }
        });

        let tripType = 'roundtrip';
        document.querySelectorAll('.trip-tabs button').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.trip-tabs button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                tripType = btn.dataset.type;
                startDate = endDate = null;
                document.getElementById('dates').value = '';
                renderCalendar();
            });
        });

        let passengerCount = 1;
        const passengersInput = document.getElementById('passengers');
        const passengersDropdown = document.getElementById('pDropdown');
        const cabinSelect = document.getElementById('cabin');
        const pCountEl = document.getElementById('pCount');
        const pMinusBtn = document.getElementById('pMinus');
        const pPlusBtn = document.getElementById('pPlus');

        passengersInput.addEventListener('click', (e) => {
            e.stopPropagation();
            passengersDropdown.classList.toggle('active');
        });

        document.addEventListener('click', () => {
            if (passengersDropdown.classList.contains('active')) {
                passengersDropdown.classList.remove('active');
            }
        });

        passengersDropdown.addEventListener('click', (e) => { e.stopPropagation(); });

        const updatePassengerDisplay = () => {
            const passengerText = passengerCount === 1 ? 'Passenger' : 'Passengers';
            passengersInput.value = `${passengerCount} ${passengerText}, ${cabinSelect.value}`;
            pCountEl.textContent = passengerCount;
            pMinusBtn.disabled = passengerCount <= 1;
            pPlusBtn.disabled = passengerCount >= 6;
        };

        pMinusBtn.addEventListener('click', () => { if (passengerCount > 1) { passengerCount--; updatePassengerDisplay(); } });
        pPlusBtn.addEventListener('click', () => { if (passengerCount < 6) { passengerCount++; updatePassengerDisplay(); } });
        cabinSelect.addEventListener('change', updatePassengerDisplay);

        const overlay = document.getElementById('overlay');
        const calendarEl = document.getElementById('cal');
        const monthTitleEl = document.getElementById('monthTitle');
        const datesInput = document.getElementById('dates');
        const prevMonthBtn = document.getElementById('prevM');
        const nextMonthBtn = document.getElementById('nextM');
        const closeDPBtn = document.getElementById('closeDP');
        const applyDatesBtn = document.getElementById('applyDates');
        
        const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        const shortMonths = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        let currentDate = new Date();
        let startDate = null;
        let endDate = null;

        datesInput.addEventListener('click', () => { overlay.classList.add('active'); renderCalendar(); });
        closeDPBtn.addEventListener('click', () => { overlay.classList.remove('active'); });
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('active'); });

        prevMonthBtn.addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); });
        nextMonthBtn.addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); });

        function renderCalendar() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth();
            monthTitleEl.textContent = `${months[month]} ${year}`;

            const firstDayOfMonth = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let calendarHTML = '';

            ['S','M','T','W','T','F','S'].forEach(day => {
                calendarHTML += `<div class="day-name">${day}</div>`;
            });

            for (let i = 0; i < firstDayOfMonth; i++) {
                calendarHTML += '<div class="day empty"></div>';
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(year, month, day);
                const dateTimestamp = date.getTime();
                let dayClasses = 'day';

                if (date < today) {
                    dayClasses += ' disabled';
                } else {
                    if (startDate && dateTimestamp === startDate.getTime()) dayClasses += ' selected';
                    if (endDate && dateTimestamp === endDate.getTime()) dayClasses += ' selected';
                    if (startDate && endDate && date > startDate && date < endDate) dayClasses += ' in-range';
                }

                calendarHTML += `<div class="${dayClasses}" data-timestamp="${dateTimestamp}">${day}</div>`;
            }

            calendarEl.innerHTML = calendarHTML;

            calendarEl.querySelectorAll('.day:not(.disabled):not(.empty)').forEach(dayEl => {
                dayEl.addEventListener('click', () => {
                    const selectedTimestamp = parseInt(dayEl.dataset.timestamp);
                    const selectedDate = new Date(selectedTimestamp);

                    if (tripType === 'oneway') {
                        startDate = selectedDate;
                        endDate = null;
                    } else {
                        if (!startDate || (startDate && endDate) || selectedDate < startDate) {
                            startDate = selectedDate;
                            endDate = null;
                        } else {
                            endDate = selectedDate;
                        }
                    }
                    renderCalendar();
                });
            });
        }

        applyDatesBtn.addEventListener('click', () => {
            if (tripType === 'oneway' && startDate) {
                const formattedDate = `${shortMonths[startDate.getMonth()]} ${startDate.getDate()}, ${startDate.getFullYear()}`;
                datesInput.value = formattedDate;
                overlay.classList.remove('active');
            } else if (tripType === 'roundtrip' && startDate && endDate) {
                const formattedStart = `${shortMonths[startDate.getMonth()]} ${startDate.getDate()}, ${startDate.getFullYear()}`;
                const formattedEnd = `${shortMonths[endDate.getMonth()]} ${endDate.getDate()}, ${endDate.getFullYear()}`;
                datesInput.value = `${formattedStart} - ${formattedEnd}`;
                overlay.classList.remove('active');
            } else {
                alert('Please select valid dates');
            }
        });

        document.getElementById('searchBtn').addEventListener('click', () => {
            const fromLocation = document.getElementById('from').value.trim();
            const toLocation = document.getElementById('to').value.trim();
            const selectedDates = document.getElementById('dates').value.trim();
            const travelersInfo = document.getElementById('passengers').value.trim();

            if (!fromLocation || !toLocation || !selectedDates) {
                alert('Please fill in all required fields (From, To, Dates)');
                return;
            }

            const searchData = {
                tripType: tripType,
                from: fromLocation,
                to: toLocation,
                dates: selectedDates,
                travelers: travelersInfo,
                cabin: cabinSelect.value
            };

            localStorage.setItem('flightSearchData', JSON.stringify(searchData));
            window.location.href = 'flightResults.php';
        });

        updatePassengerDisplay();
    </script>
    <div style="text-align: center; padding: 10px; font-size: 12px; color: #888; background: #f5f5f5; border-top: 1px solid #ddd;">
        <?php 
        $page_end_time = microtime(true);
        $execution_time = ($page_end_time - $page_start_time) * 1000;
        echo "Page generated in " . number_format($execution_time, 2) . " ms";
        ?>
    </div>
</body>
</html>
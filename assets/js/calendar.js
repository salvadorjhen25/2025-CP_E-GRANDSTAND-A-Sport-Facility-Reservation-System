// Calendar navigation state
let currentCalendarDate = new Date();
const today = new Date();

// Calendar navigation functions
function initializeCalendarNavigation() {
    const calPrevMonth = document.getElementById('calPrevMonth');
    const calNextMonth = document.getElementById('calNextMonth');
    const calPrevYear = document.getElementById('calPrevYear');
    const calNextYear = document.getElementById('calNextYear');
    
    if (calPrevMonth) {
        calPrevMonth.addEventListener('click', function() {
            // Check if going to previous month would go to past
            const newDate = new Date(currentCalendarDate);
            newDate.setMonth(newDate.getMonth() - 1);
            
            // Don't allow if it would go to a past month
            if (newDate.getFullYear() < today.getFullYear() || 
               (newDate.getFullYear() === today.getFullYear() && 
                newDate.getMonth() < today.getMonth())) {
                return;
            }
            
            currentCalendarDate = newDate;
            renderCalendarMonth(currentCalendarDate);
        });
    }

    if (calNextMonth) {
        calNextMonth.addEventListener('click', function() {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
            renderCalendarMonth(currentCalendarDate);
        });
    }

    if (calPrevYear) {
        calPrevYear.addEventListener('click', function() {
            // Don't allow going to past years
            const newYear = currentCalendarDate.getFullYear() - 1;
            if (newYear < today.getFullYear()) {
                return;
            }
            
            currentCalendarDate.setFullYear(newYear);
            renderCalendarMonth(currentCalendarDate);
        });
    }

    if (calNextYear) {
        calNextYear.addEventListener('click', function() {
            currentCalendarDate.setFullYear(currentCalendarDate.getFullYear() + 1);
            renderCalendarMonth(currentCalendarDate);
        });
    }
}

// Render the calendar month
function renderMonth(date) {
    if (!monthlyCalendar) return;
    monthlyCalendar.innerHTML = '';
    const year = date.getFullYear();
    const month = date.getMonth();
    calMonthLabel.textContent = date.toLocaleString('en-US', { month:'long' });
    
    // Update year label
    const calYearLabel = document.getElementById('calYearLabel');
    if (calYearLabel) {
        calYearLabel.textContent = year;
    }
    
    const first = new Date(year, month, 1);
    const startWeekday = first.getDay();
    const daysInMonth = new Date(year, month+1, 0).getDate();
    
    // Add empty cells for days before month starts
    for (let i=0; i<startWeekday; i++){ 
        const pad = document.createElement('div'); 
        pad.className = 'h-12'; 
        monthlyCalendar.appendChild(pad); 
    }
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let d=1; d<=daysInMonth; d++) {
        const cell = document.createElement('button');
        const cellDate = new Date(year, month, d);
        cellDate.setHours(0, 0, 0, 0);
        const isPast = cellDate < today;
        
        cell.className = `h-12 rounded-lg border text-sm font-semibold transition-all duration-200 ${
            isPast ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed' :
            'bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300'
        }`;
        
        cell.textContent = d;
        
        if (!isPast) {
            cell.addEventListener('click', () => {
                // Remove previous selection
                const allCells = monthlyCalendar.querySelectorAll('button');
                allCells.forEach(btn => btn.classList.remove('selected', 'bg-blue-500', 'text-white'));
                
                // Add selection to clicked cell
                cell.classList.add('selected', 'bg-blue-500', 'text-white');
                
                // Store selected date
                calSelected = cellDate;
                
                // Enable confirm button and update its style
                const confirmBtn = document.getElementById('confirmCalendarSelection');
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    confirmBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                }
            });
        } else {
            cell.disabled = true;
        }
        
        monthlyCalendar.appendChild(cell);
    }
}

// Update the confirm button click handler
if (confirmBtn) {
    confirmBtn.addEventListener('click', () => {
        if (!calSelected) return;
        
        // Format the date
        const year = calSelected.getFullYear();
        const month = String(calSelected.getMonth() + 1).padStart(2, '0');
        const day = String(calSelected.getDate()).padStart(2, '0');
        const formattedDate = `${year}-${month}-${day}`;
        
        // Update the booking date input
        const bookingDateInputEl = document.getElementById('booking_date');
        if (bookingDateInputEl) {
            bookingDateInputEl.value = formattedDate;
        }
        
        // Update display elements
        const bookingDateDisplay = document.getElementById('booking_date_display');
        if (bookingDateDisplay) {
            bookingDateDisplay.textContent = calSelected.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
        
        // Close calendar modal
        const calendarModal = document.getElementById('calendarModal');
        if (calendarModal) {
            calendarModal.classList.add('hidden');
        }
        
        // Trigger necessary updates
        generateTimeSlots();
        showExistingBookings();
        updateCostPreview();
    });
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Ensure current date is set to today or later
    if (currentCalendarDate < today) {
        currentCalendarDate = new Date();
    }
    
    initializeCalendarNavigation();
    
    // Initialize calendar modal
    const calendarModal = document.getElementById('calendarModal');
    const openCalBtn = document.getElementById('openCalendarModal');
    
    if (openCalBtn && calendarModal) {
        openCalBtn.addEventListener('click', () => {
            calendarModal.classList.remove('hidden');
            // Reset to current month if the selected date is in the past
            if (currentCalendarDate < today) {
                currentCalendarDate = new Date();
            }
            renderCalendarMonth(currentCalendarDate);
        });
    }
});

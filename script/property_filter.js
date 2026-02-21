// JavaScript for updating range slider displays
    document.addEventListener('DOMContentLoaded', function() {
        const updateRangeDisplay = (input, display) => {
            if (input) {
                display.textContent = input.value;
            }
        };

        const minYearBuilt = document.getElementById('minYearBuilt');
        const maxYearBuilt = document.getElementById('maxYearBuilt');
        const minYearBuiltValue = document.getElementById('minYearBuiltValue');
        const maxYearBuiltValue = document.getElementById('maxYearBuiltValue');

        if (minYearBuilt && maxYearBuilt) {
            minYearBuilt.addEventListener('input', () => updateRangeDisplay(minYearBuilt, minYearBuiltValue));
            maxYearBuilt.addEventListener('input', () => updateRangeDisplay(maxYearBuilt, maxYearBuiltValue));
            // Initial update
            updateRangeDisplay(minYearBuilt, minYearBuiltValue);
            updateRangeDisplay(maxYearBuilt, maxYearBuiltValue);
        }

        const minSqFt = document.getElementById('minSqFt');
        const maxSqFt = document.getElementById('maxSqFt');
        const minSqFtValue = document.getElementById('minSqFtValue');
        const maxSqFtValue = document.getElementById('maxSqFtValue');

        if (minSqFt && maxSqFt) {
            minSqFt.addEventListener('input', () => updateRangeDisplay(minSqFt, minSqFtValue));
            maxSqFt.addEventListener('input', () => updateRangeDisplay(maxSqFt, maxSqFtValue));
            updateRangeDisplay(minSqFt, minSqFtValue);
            updateRangeDisplay(maxSqFt, maxSqFtValue);
        }

        const minPrice = document.getElementById('minPrice');
        const maxPrice = document.getElementById('maxPrice');
        const minPriceValue = document.getElementById('minPriceValue');
        const maxPriceValue = document.getElementById('maxPriceValue');

        if (minPrice && maxPrice) {
            minPrice.addEventListener('input', () => updateRangeDisplay(minPrice, minPriceValue));
            maxPrice.addEventListener('input', () => updateRangeDisplay(maxPrice, maxPriceValue));
            updateRangeDisplay(minPrice, minPriceValue);
            updateRangeDisplay(maxPrice, maxPriceValue);
        }

        // Handle price formatting
        const priceInputs = [minPrice, maxPrice];
        const priceDisplays = [minPriceValue, maxPriceValue];

        priceInputs.forEach((input, index) => {
            if (input) {
                input.addEventListener('input', () => {
                    priceDisplays[index].textContent = '$' + Number(input.value).toLocaleString();
                });
                priceDisplays[index].textContent = '$' + Number(input.value).toLocaleString(); // Initial update
            }
        });
    });
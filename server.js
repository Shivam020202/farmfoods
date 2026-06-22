const express = require('express');
const path = require('path');
const app = express();
const PORT = process.env.PORT || 8000;

app.use(express.json());
app.use(express.static(path.join(__dirname)));

const bookingsApi = require('./api/bookings.js');
const availableDatesApi = require('./api/available-dates.js');

app.all('/api/bookings', (req, res) => bookingsApi(req, res));
app.all('/api/available-dates', (req, res) => availableDatesApi(req, res));

app.get('*', (req, res) => {
    res.sendFile(path.join(__dirname, 'index.html'));
});

app.listen(PORT, () => {
    console.log('Server running at http://localhost:' + PORT);
});

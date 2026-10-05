<?php

return [

    /*
     * How tickets are printed. 'browser' opens a print-optimised page. Add drivers here
     * once an ESC/POS implementation exists.
     */
    'printer' => env('PARKING_PRINTER', 'browser'),

];

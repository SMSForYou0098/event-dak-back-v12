<?php

namespace App\Exports;


use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class PosReportExport implements FromView
{
    protected $posReport;

    public function __construct($posReport)
    {
        $this->posReport = $posReport;
    }

    public function view(): View
    {
        return view('exports.ExportPosReport', [
            'report' => $this->posReport
        ]);
    }
}
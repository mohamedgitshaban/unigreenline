<?php

namespace Modules\Core\Printing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Answers a print request: a page laid out for A4 that the browser can print or save as PDF, or — with `?format=json`
 * — the same content as data. `?dir=rtl` lays the page out right to left.
 */
class DocumentPrinter
{
    public function respond(Request $request, PrintableDocument $document): Response|JsonResponse
    {
        if ($request->query('format') === 'json') {
            return response()->json(['data' => $document->toArray()]);
        }

        $html = view('core::print.document', ['doc' => $document, 'dir' => $request->query('dir') === 'rtl' ? 'rtl' : 'ltr'])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'inline; filename="'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->type.'-'.$document->number).'.html"']);
    }
}

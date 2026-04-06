<?php

namespace App\Services;

//use App\Models\Sale;

class ReceiptService
{
    /**
     * Generate thermal text receipt (for 58mm/80mm printers)
     */
   // In ReceiptService.php
public function generateThermalText($sale, $settings)
{
    $company = $sale->company;
    $payments = $sale->payments;
    $isSplitPayment = $payments->count() > 1;
    
    $receipt = "";
    
    // Header
    $receipt .= str_repeat("=", 32) . "\n";
    $receipt .= $this->centerText($company->name, 32) . "\n";
    $receipt .= $this->centerText($company->subtitle ?? 'AMINU AB GLOBAL CONCEPT LTD', 32) . "\n";
    $receipt .= str_repeat("-", 32) . "\n";
    $receipt .= $this->centerText($sale->invoice_number, 32) . "\n";
    $receipt .= str_repeat("-", 32) . "\n";
    
    // Customer
    $receipt .= "Customer: " . ($sale->customer->name ?? 'Walk-in Customer') . "\n";
    $receipt .= "Date: " . $sale->created_at->format('d/m/Y H:i') . "\n";
    $receipt .= "Cashier: " . ($sale->user->name ?? 'N/A') . "\n";
    $receipt .= str_repeat("-", 32) . "\n";
    
    // Items
    $receipt .= sprintf("%-18s %3s %6s %8s\n", "Item", "Qty", "Price", "Total");
    $receipt .= str_repeat("-", 32) . "\n";
    
    foreach ($sale->items as $item) {
        $name = substr($item->product_name, 0, 18);
        $qty = $item->quantity;
        $price = number_format($item->price, 2);
        $total = number_format($item->subtotal, 2);
        $receipt .= sprintf("%-18s %3d %6s %8s\n", $name, $qty, $price, $total);
    }
    
    $receipt .= str_repeat("-", 32) . "\n";
    $receipt .= sprintf("%-26s %8s\n", "Subtotal:", number_format($sale->subtotal, 2));
    
    if ($sale->discount > 0) {
        $receipt .= sprintf("%-26s %8s\n", "Discount:", "-" . number_format($sale->discount, 2));
    }
    
    $receipt .= sprintf("%-26s %8s\n", "TOTAL:", number_format($sale->total, 2));
    $receipt .= str_repeat("-", 32) . "\n";
    
    // Payment info
    if ($isSplitPayment) {
        $receipt .= "SPLIT PAYMENT:\n";
        foreach ($payments as $payment) {
            $receipt .= sprintf("  %s: %8s\n", strtoupper($payment->method), number_format($payment->amount, 2));
            if (in_array($payment->method, ['bank', 'transfer']) && $payment->bank) {
                $receipt .= sprintf("    Bank: %s/%s\n", $payment->bank->name, $payment->bank->account_number);
                if ($payment->reference) {
                    $receipt .= sprintf("    Ref: %s\n", substr($payment->reference, -8));
                }
            }
        }
        $receipt .= sprintf("Total Paid: %8s\n", number_format($sale->amount_paid, 2));
    } else {
        $payment = $payments->first();
        $receipt .= sprintf("Payment: %s\n", strtoupper($payment->method));
        $receipt .= sprintf("Amount Paid: %8s\n", number_format($sale->amount_paid, 2));
        
        if (in_array($payment->method, ['bank', 'transfer']) && $payment->bank) {
            $receipt .= sprintf("Bank: %s\n", $payment->bank->name);
            $receipt .= sprintf("A/C: %s\n", $payment->bank->account_number);
            if ($payment->reference) {
                $receipt .= sprintf("Ref: %s\n", $payment->reference);
            }
        }
    }
    
    if ($sale->change_due > 0) {
        $receipt .= sprintf("Change Due: %8s\n", number_format($sale->change_due, 2));
    }
    
    if ($sale->balance_due > 0) {
        $receipt .= sprintf("Balance Due: %8s\n", number_format($sale->balance_due, 2));
    }
    
    $receipt .= str_repeat("=", 32) . "\n";
    $receipt .= $this->centerText("Thank you!", 32) . "\n";
    $receipt .= $this->centerText("www.rayhaantechcare.com.ng", 32) . "\n";
    $receipt .= str_repeat("=", 32) . "\n";
    
    return $receipt;
}

private function centerText($text, $width)
{
    $len = strlen($text);
    if ($len >= $width) {
        return substr($text, 0, $width);
    }
    $padding = str_repeat(" ", floor(($width - $len) / 2));
    return $padding . $text . $padding;
}

    /**
     * Generate HTML receipt (for browser display)
     */
    public function generateHtmlReceipt($sale, $settings = [])
    {
        $company = $sale->company;
        $items = $sale->items;
        $payments = $sale->payments;
        
        return view('receipts.thermal', compact('sale', 'company', 'items', 'payments', 'settings'))->render();
    }

    /**
     * Generate PDF receipt (for A4 printing)
     */
    public function generatePdfReceipt($sale, $settings = [])
    {
        $company = $sale->company;
        $items = $sale->items;
        $payments = $sale->payments;
        
        $pdf = app('dompdf.wrapper');
        $pdf->loadView('receipts.standard', compact('sale', 'company', 'items', 'payments', 'settings'));
        
        return $pdf;
    }

    /**
     * Email receipt to customer
     */
    public function emailReceipt($sale, $email)
    {
        $pdf = $this->generatePdfReceipt($sale);
        
        \Mail::send([], [], function ($message) use ($sale, $pdf, $email) {
            $message->to($email)
                ->subject("Receipt - {$sale->invoice_number}")
                ->attachData($pdf->output(), "receipt-{$sale->invoice_number}.pdf", [
                    'mime' => 'application/pdf',
                ])
                ->setBody("Thank you for your purchase. Please find your receipt attached.");
        });
    }
}
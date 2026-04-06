<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            
            // Basic Info
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable()->unique();
            $table->string('barcode')->nullable();
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            
            // Pricing
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('cost', 15, 2)->default(0); // Purchase cost
            $table->decimal('wholesale_price', 15, 2)->nullable();
            $table->decimal('special_price', 15, 2)->nullable();
            $table->timestamp('special_price_from')->nullable();
            $table->timestamp('special_price_to')->nullable();
            
            // Inventory
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(5);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_backorders')->default(false);
            
            // Media
            $table->string('featured_image')->nullable();
            $table->json('gallery_images')->nullable();
            
            // Status & Settings
            $table->string('status')->default('draft'); // draft, published, archived
            $table->boolean('featured')->default(false);
            $table->json('attributes')->nullable(); // JSON for custom attributes
            $table->json('variations')->nullable(); // For product variations
            
            // SEO
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            
            $table->timestamps();
            $table->softDeletes(); // Allow soft delete
            
            // Indexes for faster queries
            $table->index(['company_id', 'status', 'featured']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'vendor_id']);
            $table->index('sku');
            $table->index('slug');
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
};
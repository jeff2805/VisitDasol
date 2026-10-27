{{-- Shared by the add and edit screens for what a resort offers. --}}
<div class="row g-4">
    <div class="col-lg-7">
        <section class="vd-panel p-4">
            <x-form.input name="name" label="What is it" :value="$extra->name" required maxlength="120"
                          help="For example: Kayak hire, Banana boat ride, Island hopping." />

            <div class="mb-3">
                <label class="form-label" for="field-description">
                    Description <span class="text-muted-vd fw-normal small">(optional)</span>
                </label>
                <textarea class="form-control @error('description') is-invalid @enderror"
                          id="field-description" name="description" rows="4"
                          placeholder="How long it lasts, what is included, anything a guest should bring.">{{ old('description', $extra->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="vd-panel p-4">
            <h2 class="h5 mb-3">Price</h2>

            <div class="mb-3">
                <label class="form-label" for="field-price">Price</label>
                <div class="input-group has-validation">
                    <span class="input-group-text">₱</span>
                    <input type="number" class="form-control @error('price') is-invalid @enderror"
                           id="field-price" name="price" min="1" step="1" required
                           value="{{ old('price', $extra->price ? (int) $extra->price : '') }}">
                    @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- The one thing that most often goes wrong on a resort
                 bill. A boat priced for the whole boat and charged per
                 head turns a family of six into six times the money. --}}
            <x-form.select name="price_unit" label="What does that price cover" required
                           :options="$units" :value="$extra->price_unit?->value"
                           placeholder="Choose one"
                           help="Charge a whole-boat price per person and a family of six pays six times over." />

            <x-form.input name="max_quantity" type="number" label="Most a guest may ask for at once"
                          :value="$extra->max_quantity ?? 10" required min="1" max="100"
                          help="Put 1 if you only have one. Stops a mistyped number becoming a request for 500." />

            <x-form.input name="sort_order" type="number" label="Position in the list"
                          :value="$extra->sort_order ?? 0" min="0" max="999"
                          help="Optional. Lower numbers appear first; equal numbers sort by name." />
        </section>
    </div>
</div>

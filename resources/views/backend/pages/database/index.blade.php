@extends('backend.layouts.master')
@section('title','Database')
@push('styles')
@endpush
@section('main-content')
<div class="content">    
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap row-gap-3">
            <h4 class="card-title">Database</h4>
            
        </div>
        <div class="card-body p-1">
            <div class="table-responsive">
               <form method="POST" action="{{ route('truncate.tables') }}">
                    @csrf
                    <table class="table table">
                        <thead>
                            <tr>
                                <th>Select <BR><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                <th>Table Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tableNames as $table)
                                <tr>
                                    <td>
                                        @if(in_array($table, ['member_types', 'menus', 'migrations', 'role_menu', 'roles', 'user_role', 'users', 'blog_categories', 'blog_subcategories']))
                                            <input class="form-check-input" type="checkbox" disabled>
                                        @else
                                            <input type="checkbox" class="form-check-input table-checkbox" name="tables[]" value="{{ $table }}">
                                        @endif
                                    
                                    </td>
                                    <td>{{ $table }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" data-name="Truncate Selected Tables" class="btn btn-primary btn-sm show_confirm">Truncate Selected Tables</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
@push('scripts')
<script>
    document.getElementById('selectAll').addEventListener('change', function () {
        const checkboxes = document.querySelectorAll('.table-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
   $(document).ready(function() {
      $('.show_confirm').click(function(event) {
         var form = $(this).closest("form"); 
         var name = $(this).data("name"); 
         event.preventDefault();     

         Swal.fire({
               title: `Are you sure you want to delete this ${name}?`,
               text: "If you delete this, it will be gone forever.",
               icon: "warning",
               showCancelButton: true,
               confirmButtonText: "Yes, delete it!",
               cancelButtonText: "Cancel",
               dangerMode: true,
         }).then((result) => {
               if (result.isConfirmed) {
                  form.submit();
               }
         });
      });
           
   });
</script>
@endpush
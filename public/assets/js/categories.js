
function submitLogoChange(catId) {
    const form = document.getElementById(`form-update-logo-${catId}`);
    if (form) {
        if (confirm("Bạn có chắc chắn muốn thay đổi ảnh đại diện cho danh mục này không?")) {
            form.submit();
        } else {
            const fileInput = document.getElementById(`input-logo-${catId}`);
            if (fileInput) fileInput.value = "";
        }
    }
}


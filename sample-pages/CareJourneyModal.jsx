export default function Modal({ children, onClose }) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-30 overflow-hidden"
      onClick={onClose}
    >
      <div
        className="relative w-full max-w-5xl max-h-screen overflow-y-auto bg-white rounded-lg p-4"
        onClick={(e) => e.stopPropagation()}
      >
        {children}
      </div>
    </div>
  );
}

